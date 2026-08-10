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
use App\Plugin\PluginPage;
use App\Service\PedigreeBuilder;
use PDO;

class Plugin {

    /**
     * Generationstiefe JE ELTERNTEIL für den Abschnitt auf der Detailseite (#72).
     * Entspricht RechnerController::DEFAULT_DEPTH, damit Detailseite und
     * Verpaarungsrechner bei identischer Datenlage denselben Wert liefern.
     */
    public const DETAIL_PARENT_DEPTH = 6;

    public function register(HookManager $hooks): void {
        $hooks->addFilter('horse.detail_sections', [$this, 'addDetailSection']);
    }

    /**
     * Filter: hängt einen Abschnitt mit dem berechneten Inzuchtkoeffizienten
     * an die öffentliche Detailseite an.
     *
     * Tiefensemantik (#72): PedigreeBuilder::build() zählt die WURZEL als
     * Generation 1. Der vom Kern übergebene Detailseiten-Baum ($pedigree, Tiefe
     * 6) hat das PFERD als Wurzel - seine Teilbäume ['sire']/['dam'] reichen je
     * Elternteil damit nur 5 Generationen, während der Verpaarungsrechner das
     * ELTERNTEIL als Wurzel baut und 6 erreicht. Ein gemeinsamer Vorfahre in
     * der sechsten Generation erschien so im Rechner, verschwand aber auf der
     * Detailseite des Fohlens. Deshalb baut der Abschnitt je Elternteil einen
     * EIGENEN Baum mit dem Elternteil als Wurzel (DETAIL_PARENT_DEPTH
     * Generationen) statt den fremd-parametrisierten Kern-Baum zu übernehmen;
     * PedigreeBuilder memoisiert je build()-Aufruf, die zwei zusätzlichen
     * Aufbauten bleiben günstig. publishedOnly=true wie im Kern: aus
     * unveröffentlichten Daten darf öffentlich nichts hergeleitet werden.
     */
    public function addDetailSection(array $sections, array $horse, array $horsePersons, ?array $pedigree): array {
        $sireTree = PedigreeBuilder::build(
            !empty($horse['sire_id']) ? (int) $horse['sire_id'] : null,
            self::DETAIL_PARENT_DEPTH,
            true
        );
        $damTree = PedigreeBuilder::build(
            !empty($horse['dam_id']) ? (int) $horse['dam_id'] : null,
            self::DETAIL_PARENT_DEPTH,
            true
        );

        // Ohne einen einzigen auflösbaren Eltern-Baum gäbe es nur eine
        // inhaltsleere "0,00 %"-Aussage - dann lieber gar kein Abschnitt.
        if ($sireTree === null && $damTree === null) {
            return $sections;
        }

        $coi = CoiCalculator::fromParentTrees($sireTree, $damTree);
        $percent = number_format($coi * 100, 2, ',', '.');

        $sections[] = '<div style="margin-top:0.5rem;padding:0.75rem 1rem;background:var(--surface-muted);border-radius:var(--border-radius, 6px);">'
            . '<strong>🧬 Inzuchtkoeffizient (Wright\'s COI):</strong> ' . $percent . ' %'
            . '<p style="margin:0.4rem 0 0 0;color:var(--text-muted);font-size:0.85em;">'
            . 'Berechnet aus dem verfügbaren, bis zu ' . self::DETAIL_PARENT_DEPTH . ' Generationen je Elternteil tiefen Stammbaum. '
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
            [
                'method' => 'GET',
                'path' => '/suche',
                'callback' => [RechnerController::class, 'suche'],
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
 * Vorfahren sind. Wrights Pfadregel verlangt dabei, dass in einem Pfad kein
 * Individuum mehr als einmal vorkommt - unterhalb eines gemeinsamen
 * Vorfahren wird daher nicht weitergesammelt, denn dessen eigene Ahnen sind
 * nur durch ihn hindurch erreichbar und stecken korrekt ausschließlich im
 * Term (1+F_A). Dieser Term selbst wird bewusst nicht rekursiv nachberechnet -
 * das würde bei jedem Aufruf zusätzliche, potenziell exponentiell viele
 * PedigreeBuilder-Abfragen auslösen (kein Caching, siehe
 * docs/plugin-development.md). Für die verfügbare Tiefe (max. 6-8
 * Generationen) ist die dadurch entstehende geringe Unterschätzung in der
 * Praxis vernachlässigbar.
 */
class CoiCalculator {

    public static function fromParentTrees(?array $sireTree, ?array $damTree): float {
        // Erster Durchlauf ohne Abbruch: bestimmt die Menge der IDs, die in
        // beiden Teilbäumen vorkommen (gemeinsame Vorfahren).
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
     * Sammelt für jeden erreichbaren, echten (nicht-Platzhalter) Vorfahren im
     * Teilbaum die Anzahl an Generationsschritten ("Links") vom übergebenen
     * Elternteil aus. Ein Pferd kann mehrfach mit unterschiedlicher
     * Schrittzahl auftreten (mehrere Abstammungspfade) - alle Vorkommen
     * fließen einzeln in die Summe ein, das ist im Pfad-Koeffizienten-Verfahren
     * so vorgesehen.
     *
     * Ist `$stopAt` gesetzt (Menge gemeinsamer Vorfahren-IDs), endet die
     * Rekursion an jedem darin enthaltenen Knoten: seine eigenen Ahnen dürfen
     * nach Wrights Pfadregel nicht als weitere "gemeinsame Vorfahren" gezählt
     * werden, da jeder Pfad zu ihnen den bereits gezählten Vorfahren erneut
     * enthielte.
     *
     * @param array<int, list<int>> &$map Vorfahren-ID => Liste der Schrittzahlen
     * @param array<int, mixed> $stopAt IDs, an denen die Rekursion endet
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
 * Verpaarungsrechner: schätzt den COI eines Fohlens aus zwei frei wählbaren,
 * (noch) nicht notwendigerweise verpaarten Elterntieren. Rein GET-basiert
 * (keine Datenänderung), Zugriffsschutz über die selbst registrierte
 * Berechtigung "inzuchtkoeffizient.calculate".
 */
class RechnerController extends BaseController {

    private const DEFAULT_DEPTH = 6;
    private const MAX_DEPTH = 8;
    /** Maximale Trefferzahl der /suche-Route (#74). */
    private const SUCHE_LIMIT = 50;

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
        $this->requirePermission('inzuchtkoeffizient', 'calculate');
    }

    public function show(): void {
        $sireId = isset($_GET['sire_id']) && $_GET['sire_id'] !== '' ? (int) $_GET['sire_id'] : null;
        $damId = isset($_GET['dam_id']) && $_GET['dam_id'] !== '' ? (int) $_GET['dam_id'] : null;
        $depth = isset($_GET['depth']) ? max(1, min(self::MAX_DEPTH, (int) $_GET['depth'])) : self::DEFAULT_DEPTH;

        // Statt des früheren Komplett-SELECT über den gesamten Pferdebestand
        // (#74) werden nur noch die tatsächlich ausgewählten IDs nachgeschlagen -
        // für die Vorbelegung der Suchfelder und die Geschlechtsprüfung. Die
        // Auswahl selbst läuft über <input list> + <datalist> mit der
        // /suche-Route (höchstens SUCHE_LIMIT Treffer).
        $selected = self::fetchHorsesById(array_filter([$sireId, $damId]));

        // Serverseitige Prüfung (#54): Die Beschriftung "Hengst/Stute" darf
        // keine Prüfung suggerieren, die nicht stattfindet - rollen-widrige
        // IDs (Stute als Vater, Hengst/Wallach als Mutter) werden verworfen,
        // egal was der Client schickt. NULL-Geschlecht besteht die Prüfung.
        $sexErrors = [];
        if ($sireId !== null && in_array($selected[$sireId]['sex'] ?? null, ['mare', 'gelding'], true)) {
            $sexErrors[] = 'Das als Hengst (Vater) gewählte Pferd ist als ' . (($selected[$sireId]['sex'] === 'mare') ? 'Stute' : 'Wallach') . ' erfasst.';
            $sireId = null;
        }
        if ($damId !== null && in_array($selected[$damId]['sex'] ?? null, ['stallion', 'gelding'], true)) {
            $sexErrors[] = 'Das als Stute (Mutter) gewählte Pferd ist als ' . (($selected[$damId]['sex'] === 'stallion') ? 'Hengst' : 'Wallach') . ' erfasst.';
            $damId = null;
        }

        $result = null;
        if ($sireId && $damId) {
            $sireTree = PedigreeBuilder::build($sireId, $depth);
            $damTree = PedigreeBuilder::build($damId, $depth);
            if ($sireTree !== null && $damTree !== null) {
                $coi = CoiCalculator::fromParentTrees($sireTree, $damTree);
                $result = number_format($coi * 100, 2, ',', '.');
            }
        }

        // Inhalt als Fragment im Haupt-Layout über PluginPage (Addons#66):
        // Header, Navigation, Theme-Umschalter und Grund-Styling (Formulare,
        // Schrift) kommen vom Framework. Addon-spezifisch bleibt nur die
        // Ergebnis-Box; Farben über Theme-Variablen.
        $content = '<style>';
        $content .= '.inzucht-result{margin-top:1.5rem;padding:1rem;background:var(--surface-muted);border-radius:var(--border-radius, 6px);font-size:1.1rem;}';
        $content .= '</style>';

        $content .= '<div class="card">';
        $content .= '<h1>🧬 Verpaarungsrechner</h1>';
        $content .= '<p>Schätzt den voraussichtlichen Inzuchtkoeffizienten eines Fohlens aus zwei ausgewählten Elterntieren.</p>';
        $content .= '<form method="GET">';

        if ($sexErrors !== []) {
            $content .= '<p style="color:var(--danger-fg);background:var(--danger-soft-bg);padding:0.6rem 0.8rem;border-radius:var(--border-radius, 4px);">'
                . htmlspecialchars(implode(' ', $sexErrors), ENT_QUOTES, 'UTF-8') . ' Die Auswahl wurde verworfen.</p>';
        }

        // Geschlechtsabhängige Vorschläge (#54) übernimmt die /suche-Route über
        // den rolle-Parameter: "Hengst (Vater)" liefert keine Stuten/Wallache,
        // "Stute (Mutter)" keine Hengste/Wallache - Pferde ohne Geschlechts-
        // angabe (NULL, Altbestand) bleiben in beiden Rollen wählbar,
        // konsistent zur NULL-Regel des Kerns (Framework #165).
        $content .= self::searchFieldHtml('sire_id', 'Hengst (Vater)', 'sire',
            $sireId !== null ? ($selected[$sireId] ?? null) : null);
        $content .= self::searchFieldHtml('dam_id', 'Stute (Mutter)', 'dam',
            $damId !== null ? ($selected[$damId] ?? null) : null);

        $content .= '<div class="form-group">';
        $content .= '<label for="depth">Generationstiefe (1-' . self::MAX_DEPTH . ')</label>';
        $content .= '<input type="number" name="depth" id="depth" class="form-control" min="1" max="' . self::MAX_DEPTH . '" value="' . (int) $depth . '">';
        $content .= '</div>';

        $content .= '<p><button type="submit" class="btn">Berechnen</button></p>';
        $content .= '</form>';

        if ($result !== null) {
            $content .= '<div class="inzucht-result">Voraussichtlicher Inzuchtkoeffizient: <strong>' . $result . ' %</strong></div>';
        } elseif ($sireId && $damId) {
            $content .= '<div class="inzucht-result">Für mindestens eines der ausgewählten Pferde konnte kein Stammbaum ermittelt werden.</div>';
        }

        $content .= '<p style="margin-top:2rem;"><a href="/admin" class="btn btn-secondary">Zurück zum Dashboard</a></p>';
        $content .= '</div>';

        // Befüllt die datalists über die /suche-Route und überträgt die im
        // "[#id]"-Suffix des gewählten Eintrags kodierte ID in das begleitende
        // Hidden-Feld (sire_id/dam_id) - die Route erhält also weiterhin
        // numerische IDs, und Ergebnis-URLs bleiben teilbar wie bisher.
        $content .= '<script>
            (function () {
                var idMuster = /\[#(\d+)\]\s*$/;
                document.querySelectorAll("input.ik-suchfeld").forEach(function (feld) {
                    var ziel = document.getElementById(feld.dataset.ziel);
                    var liste = document.getElementById(feld.getAttribute("list"));
                    var timer = null;

                    function uebernehmen() {
                        var treffer = feld.value.match(idMuster);
                        ziel.value = treffer ? treffer[1] : "";
                    }

                    feld.addEventListener("input", function () {
                        uebernehmen();
                        if (timer) { clearTimeout(timer); }
                        timer = setTimeout(function () {
                            var q = feld.value.replace(idMuster, "").trim();
                            fetch("/plugin/inzuchtkoeffizient/suche?q=" + encodeURIComponent(q)
                                    + "&rolle=" + encodeURIComponent(feld.dataset.rolle))
                                .then(function (antwort) { return antwort.ok ? antwort.json() : []; })
                                .then(function (zeilen) {
                                    liste.textContent = "";
                                    zeilen.forEach(function (zeile) {
                                        var option = document.createElement("option");
                                        option.value = zeile.label;
                                        liste.appendChild(option);
                                    });
                                })
                                .catch(function () { /* Vorschläge sind Komfort - still scheitern lassen */ });
                        }, 200);
                    });
                    feld.addEventListener("change", uebernehmen);
                });
            })();
        </script>';

        PluginPage::render('Verpaarungsrechner', $content);
    }

    /**
     * JSON-Suchroute für die <datalist>-Auswahlfelder (#74): liefert höchstens
     * SUCHE_LIMIT Treffer statt des früheren Gesamtbestands im <select>.
     * Sichtbarkeit wie im Rechner selbst (bewusst auch unveröffentlichte
     * Pferde, denn die Route läuft durch denselben berechtigungsprüfenden
     * Konstruktor); rolle=sire/dam wendet die Geschlechtsregeln aus #54
     * bereits auf die Vorschläge an.
     */
    public function suche(): void {
        header('Content-Type: application/json; charset=utf-8');

        $q = trim((string) ($_GET['q'] ?? ''));
        $rolle = (string) ($_GET['rolle'] ?? '');

        $where = 'deleted_at IS NULL';
        $params = [];
        if ($q !== '') {
            // Teilstring-Suche, gleiche Bauart wie katalog-export/Kern-Katalog.
            $where .= ' AND name LIKE ?';
            $params[] = '%' . $q . '%';
        }
        // NOT IN lässt NULL-Zeilen nie passieren, daher das ausdrückliche
        // "sex IS NULL OR" (NULL-Regel des Kerns, Framework #165).
        if ($rolle === 'sire') {
            $where .= " AND (sex IS NULL OR sex NOT IN ('mare', 'gelding'))";
        } elseif ($rolle === 'dam') {
            $where .= " AND (sex IS NULL OR sex NOT IN ('stallion', 'gelding'))";
        }

        $stmt = Database::getInstance()->prepare(
            "SELECT id, name, birth_year FROM horses WHERE {$where} ORDER BY name ASC LIMIT " . self::SUCHE_LIMIT
        );
        $stmt->execute($params);

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = ['id' => (int) $row['id'], 'label' => self::horseLabel($row)];
        }

        echo json_encode($result);
        exit;
    }

    /**
     * Ein Auswahlfeld als <input list> + <datalist> + Hidden-Feld (#74). Die
     * datalist wird clientseitig über die /suche-Route befüllt; der sichtbare
     * Eintragstext endet auf "[#<id>]", woraus das eingebettete Skript die ID
     * für das Hidden-Feld gewinnt - eindeutig auch bei namensgleichen Pferden.
     *
     * @param array{id: int|string, name: string, birth_year: mixed}|null $horse
     *   Bereits ausgewähltes Pferd zur Vorbelegung (oder null).
     */
    private static function searchFieldHtml(string $field, string $label, string $role, ?array $horse): string {
        $display = $horse !== null ? self::horseLabel($horse) : '';
        return '<div class="form-group">'
            . '<label for="' . $field . '_suche">' . $label . '</label>'
            . '<input type="text" id="' . $field . '_suche" class="form-control ik-suchfeld" list="' . $field . '_liste"'
            . ' data-rolle="' . $role . '" data-ziel="' . $field . '"'
            . ' placeholder="Name eintippen und Vorschlag übernehmen …" autocomplete="off"'
            . ' value="' . htmlspecialchars($display, ENT_QUOTES, 'UTF-8') . '">'
            . '<datalist id="' . $field . '_liste"></datalist>'
            . '<input type="hidden" name="' . $field . '" id="' . $field . '" value="' . ($horse !== null ? (int) $horse['id'] : '') . '">'
            . '</div>';
    }

    /** Anzeigetext eines Treffers/der Vorbelegung, inkl. "[#id]"-Suffix. */
    private static function horseLabel(array $horse): string {
        return $horse['name']
            . (!empty($horse['birth_year']) ? ' (' . $horse['birth_year'] . ')' : '')
            . ' [#' . (int) $horse['id'] . ']';
    }

    /**
     * Lädt genau die übergebenen Pferde-IDs (statt des Gesamtbestands, #74).
     *
     * @param array<int, int> $ids
     * @return array<int, array<string, mixed>> Pferd-Zeilen, indiziert nach ID
     */
    private static function fetchHorsesById(array $ids): array {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::getInstance()->prepare(
            "SELECT id, name, birth_year, sex FROM horses WHERE id IN ({$placeholders}) AND deleted_at IS NULL"
        );
        $stmt->execute($ids);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), null, 'id');
    }
}
