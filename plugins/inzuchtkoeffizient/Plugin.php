<?php
// inzuchtkoeffizient/Plugin.php
//
// Addon für Hengstverzeichnis_Framework: berechnet Wright's Inzuchtkoeffizienten
// (COI) auf Basis des bestehenden Pedigree-Baums (App\Service\PedigreeBuilder)
// und zeigt ihn auf der öffentlichen Pferde-Detailseite an. Zusätzlich steht
// Admins/Editoren mit der Berechtigung "inzuchtkoeffizient.calculate" ein
// Verpaarungsrechner zur Verfügung, der den voraussichtlichen COI eines
// Fohlens aus zwei frei wählbaren Elterntieren schätzt.
//
// Installation (lokal im Framework-Repo):
//   cp -r inzuchtkoeffizient plugins/inzuchtkoeffizient
// Danach unter Admin -> Plugins verwalten (/admin/plugins) aktivieren.
//
// Siehe docs/plugin-development.md im Framework-Repo für die vollständige
// Hook-/Routen-/Berechtigungs-Referenz.

namespace Plugin\Inzuchtkoeffizient;

use App\Controllers\BaseController;
use App\Database;
use App\Plugin\HookManager;
use App\Service\PedigreeBuilder;
use PDO;

class Plugin {

    public function register(HookManager $hooks): void {
        $hooks->addFilter('horse.detail_sections', [$this, 'addDetailSection']);
    }

    /**
     * Filter-Beispiel: hängt einen Abschnitt mit dem berechneten Inzuchtkoeffizienten
     * an die öffentliche Detailseite an. Nutzt den bereits vom Kern berechneten
     * 6-Generationen-Baum aus dem vierten Filter-Parameter - keine eigene
     * PedigreeBuilder-Abfrage nötig, damit auf jeder Detailseite nicht zusätzlich
     * ein potenziell teurer rekursiver Baum-Aufbau anfällt.
     */
    public function addDetailSection(array $sections, array $horse, array $horsePersons, ?array $pedigree): array {
        if ($pedigree === null) {
            return $sections;
        }

        $coi = CoiCalculator::fromParentTrees($pedigree['sire'] ?? null, $pedigree['dam'] ?? null);
        $percent = number_format($coi * 100, 2, ',', '.');

        $sections[] = '<div style="margin-top:0.5rem;padding:0.75rem 1rem;background:#f8f9fa;border-radius:6px;">'
            . '<strong>🧬 Inzuchtkoeffizient (Wright\'s COI):</strong> ' . $percent . ' %'
            . '<p style="margin:0.4rem 0 0 0;color:#666;font-size:0.85em;">'
            . 'Berechnet aus dem verfügbaren, bis zu 6 Generationen tiefen Stammbaum. '
            . 'Vereinfachte Formel (gemeinsame Vorfahren selbst als nicht ingezüchtet angenommen).'
            . '</p></div>';

        return $sections;
    }

    /**
     * Berechtigungs-Beispiel (#66): eigenes, neues Modul "inzuchtkoeffizient" mit
     * einer Aktion "calculate" für den Verpaarungsrechner.
     *
     * @return array<int, array{module:string, action:string, label:string, module_label:string}>
     */
    public function permissions(): array {
        return [
            [
                'module' => 'inzuchtkoeffizient',
                'action' => 'calculate',
                'label' => 'Verpaarungsrechner nutzen',
                'module_label' => 'Inzuchtkoeffizient',
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
                'path' => '/rechner',
                'callback' => [RechnerController::class, 'show'],
            ],
        ];
    }
}

/**
 * Reine Rechen-Logik, unabhängig von HTTP/Controller - so in zwei Kontexten
 * wiederverwendbar: dem automatischen Abschnitt auf der Detailseite (echtes
 * Pferd, bereits vorhandener Baum) und dem Verpaarungsrechner (zwei frei
 * gewählte, ggf. noch nicht verpaarte Elterntiere).
 *
 * Verwendet die im Zuchtwesen übliche Näherungsformel
 * F = Σ (0,5)^(n1+n2+1) über alle gemeinsamen Vorfahren, wobei n1/n2 die
 * Anzahl der Generationsschritte vom jeweiligen Elternteil zum gemeinsamen
 * Vorfahren sind. Der exakte Wright-Term (1+F_A) für die Ingezüchtetheit des
 * gemeinsamen Vorfahren selbst wird bewusst nicht rekursiv nachberechnet -
 * das würde bei jedem Aufruf zusätzliche, potenziell exponentiell viele
 * PedigreeBuilder-Abfragen auslösen (kein Caching, siehe
 * docs/plugin-development.md). Für die verfügbare Tiefe (max. 6-8
 * Generationen) ist die Abweichung in der Praxis gering.
 */
class CoiCalculator {

    public static function fromParentTrees(?array $sireTree, ?array $damTree): float {
        $sireOccurrences = [];
        self::collectAncestors($sireTree, 0, $sireOccurrences);

        $damOccurrences = [];
        self::collectAncestors($damTree, 0, $damOccurrences);

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
     * Sammelt für jeden erreichbaren, echten (nicht-Platzhalter) Vorfahren im
     * Teilbaum die Anzahl an Generationsschritten ("Links") vom übergebenen
     * Elternteil aus. Ein Pferd kann mehrfach mit unterschiedlicher
     * Schrittzahl auftreten (mehrere Abstammungspfade) - alle Vorkommen
     * fließen einzeln in die Summe ein, das ist im Pfad-Koeffizienten-Verfahren
     * so vorgesehen.
     *
     * @param array<int, int> &$map Vorfahren-ID => Liste der Schrittzahlen
     */
    private static function collectAncestors(?array $node, int $links, array &$map): void {
        if ($node === null || empty($node['id']) || !empty($node['is_placeholder'])) {
            return;
        }

        $map[$node['id']][] = $links;

        self::collectAncestors($node['sire'] ?? null, $links + 1, $map);
        self::collectAncestors($node['dam'] ?? null, $links + 1, $map);
    }
}

/**
 * Verpaarungsrechner: schätzt den COI eines Fohlens aus zwei frei wählbaren,
 * (noch) nicht notwendigerweise verpaarten Elterntieren. Rein GET-basiert
 * (keine Datenänderung), Zugriffsschutz über die selbst registrierte
 * Berechtigung "inzuchtkoeffizient.calculate".
 */
class RechnerController extends BaseController {

    private const DEFAULT_DEPTH = 6;
    private const MAX_DEPTH = 8;

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
        $this->requirePermission('inzuchtkoeffizient', 'calculate');
    }

    public function show(): void {
        $db = Database::getInstance();
        $horses = $db->query(
            "SELECT id, name, birth_year FROM horses WHERE deleted_at IS NULL ORDER BY name ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $sireId = isset($_GET['sire_id']) && $_GET['sire_id'] !== '' ? (int) $_GET['sire_id'] : null;
        $damId = isset($_GET['dam_id']) && $_GET['dam_id'] !== '' ? (int) $_GET['dam_id'] : null;
        $depth = isset($_GET['depth']) ? max(1, min(self::MAX_DEPTH, (int) $_GET['depth'])) : self::DEFAULT_DEPTH;

        $result = null;
        if ($sireId && $damId) {
            $sireTree = PedigreeBuilder::build($sireId, $depth);
            $damTree = PedigreeBuilder::build($damId, $depth);
            if ($sireTree !== null && $damTree !== null) {
                $coi = CoiCalculator::fromParentTrees($sireTree, $damTree);
                $result = number_format($coi * 100, 2, ',', '.');
            }
        }

        echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><title>Verpaarungsrechner</title>';
        echo '<style>body{font-family:sans-serif;padding:2rem;max-width:700px;margin:0 auto;}';
        echo 'label{display:block;margin-top:1rem;font-weight:bold;} select,input{width:100%;padding:0.5rem;margin-top:0.3rem;}';
        echo '.result{margin-top:1.5rem;padding:1rem;background:#f8f9fa;border-radius:6px;font-size:1.1rem;}</style>';
        echo '</head><body>';
        echo '<h1>🧬 Verpaarungsrechner</h1>';
        echo '<p>Schätzt den voraussichtlichen Inzuchtkoeffizienten eines Fohlens aus zwei ausgewählten Elterntieren.</p>';
        echo '<form method="GET">';

        echo '<label for="sire_id">Hengst (Vater)</label><select name="sire_id" id="sire_id">';
        echo '<option value="">– auswählen –</option>';
        foreach ($horses as $h) {
            $selected = ($sireId === (int) $h['id']) ? ' selected' : '';
            echo '<option value="' . (int) $h['id'] . '"' . $selected . '>'
                . htmlspecialchars($h['name'] . ($h['birth_year'] ? ' (' . $h['birth_year'] . ')' : ''), ENT_QUOTES, 'UTF-8')
                . '</option>';
        }
        echo '</select>';

        echo '<label for="dam_id">Stute (Mutter)</label><select name="dam_id" id="dam_id">';
        echo '<option value="">– auswählen –</option>';
        foreach ($horses as $h) {
            $selected = ($damId === (int) $h['id']) ? ' selected' : '';
            echo '<option value="' . (int) $h['id'] . '"' . $selected . '>'
                . htmlspecialchars($h['name'] . ($h['birth_year'] ? ' (' . $h['birth_year'] . ')' : ''), ENT_QUOTES, 'UTF-8')
                . '</option>';
        }
        echo '</select>';

        echo '<label for="depth">Generationstiefe (1-' . self::MAX_DEPTH . ')</label>';
        echo '<input type="number" name="depth" id="depth" min="1" max="' . self::MAX_DEPTH . '" value="' . (int) $depth . '">';

        echo '<p><button type="submit" style="margin-top:1rem;padding:0.6rem 1.2rem;">Berechnen</button></p>';
        echo '</form>';

        if ($result !== null) {
            echo '<div class="result">Voraussichtlicher Inzuchtkoeffizient: <strong>' . $result . ' %</strong></div>';
        } elseif ($sireId && $damId) {
            echo '<div class="result">Für mindestens eines der ausgewählten Pferde konnte kein Stammbaum ermittelt werden.</div>';
        }

        echo '<p style="margin-top:2rem;"><a href="/admin">Zurück zum Dashboard</a></p>';
        echo '</body></html>';
    }
}
