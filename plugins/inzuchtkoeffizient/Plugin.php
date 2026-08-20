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
// Die Rechnung selbst steht seit Addons#123 NICHT mehr hier, sondern im
// gemeinsamen Rechenkern WrightCoi.php (siehe dort für das Warum).
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
// CoiCalculator eine eigene Klasse mit eigener Rechnung. Der Alias hält
// bestehende Verweise (u. a. tests/Unit/InzuchtkoeffizientCoiTest.php und der
// Querverweis in plugins/genealogie-vergleich) am Leben, OHNE eine zweite
// Fassung zu sein - class_alias() erzeugt keinen eigenen Code, sondern einen
// zweiten Namen für exakt dieselbe Klasse.
if (!class_exists(__NAMESPACE__ . '\\CoiCalculator', false)) {
    class_alias(WrightCoi::class, __NAMESPACE__ . '\\CoiCalculator');
}

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

        $coi = WrightCoi::fromParentTrees($sireTree, $damTree);
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
        // Die addoneigene Pferdesuche (/suche) ist mit Addons#125 entfallen.
        // Sieben Addons brachten dieselbe Route und denselben JS-Block mit;
        // der Kern liefert beides seit Framework#341 unter
        // /admin/horses/search bzw. /js/horse-search.js.
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
 * Verpaarungsrechner: schätzt den COI eines Fohlens aus zwei frei wählbaren,
 * (noch) nicht notwendigerweise verpaarten Elterntieren. Rein GET-basiert
 * (keine Datenänderung), Zugriffsschutz über die selbst registrierte
 * Berechtigung "inzuchtkoeffizient.calculate".
 */
class RechnerController extends BaseController {

    private const DEFAULT_DEPTH = 6;
    private const MAX_DEPTH = 8;
    // Die maximale Trefferzahl der Pferdesuche steht seit Addons#125 im Kern
    // (HorseSearchController::MAX_TREFFER) - die addoneigene Konstante ist
    // mit der Route entfallen, die sie deckelte.

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
        // Auswahl selbst läuft seit Addons#125 über das gemeinsame Suchfeld
        // des Kerns (/admin/horses/search, dort gedeckelt).
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
                $coi = WrightCoi::fromParentTrees($sireTree, $damTree);
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

        // Geschlechtsabhängige Vorschläge (#54) reicht das gemeinsame Suchfeld
        // als `rolle=sire|dam` an den Kern-Endpunkt durch (Addons#125):
        // "Hengst (Vater)" soll keine Stuten/Wallache vorschlagen, "Stute
        // (Mutter)" keine Hengste/Wallache - Pferde ohne Geschlechtsangabe
        // (NULL, Altbestand) bleiben in beiden Rollen wählbar, konsistent zur
        // NULL-Regel des Kerns (Framework #165).
        //
        // ACHTUNG: Der Kern-Endpunkt versteht unter `rolle` derzeit die Rolle
        // aus horse_persons (breeder/owner/keeper) und ignoriert sire/dam
        // stillschweigend - siehe Framework#341. Bis das nachgezogen ist,
        // sind die VORSCHLÄGE nicht nach Geschlecht gefiltert. Die
        // eigentliche Prüfung hängt nicht daran: Die rollenwidrige Auswahl
        // wird oben serverseitig verworfen, ganz gleich, was der Client
        // schickt.
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

        // Progressive Enhancement: Das Skript des Kerns (Framework#341)
        // verdrahtet jedes Feld mit der Klasse `hv-pferdesuche` mit dem
        // Endpunkt und füllt das über `data-ziel` benannte <select>. Der
        // frühere addoneigene Block ist mit Addons#125 entfallen - samt
        // seines unbehandelten Wettlaufs zwischen zwei schnell
        // aufeinanderfolgenden Anfragen und samt der "[#id]"-Krücke, mit der
        // er die ID aus dem Anzeigetext zurückgewann.
        $content .= '<script src="/js/horse-search.js"></script>';

        PluginPage::render('Verpaarungsrechner', $content);
    }

    /**
     * Ein Auswahlfeld aus dem gemeinsamen Suchfeld des Kerns (Addons#125):
     * ein Textfeld mit der Klasse `hv-pferdesuche`, das /js/horse-search.js
     * verdrahtet, und das <select>, das es füllt. Das <select> trägt den
     * Feldnamen - die Route bekommt also weiterhin eine numerische ID, und
     * eine Ergebnis-URL bleibt teilbar wie bisher.
     *
     * Die "[#<id>]"-Krücke des alten Felds ist damit weg: Sie kodierte die ID
     * in den ANZEIGETEXT, weil eine <datalist> nur Text zurückgibt. Ein
     * <option> trägt Wert und Beschriftung getrennt und braucht das nicht.
     *
     * Eine bereits getroffene Auswahl wird als einzige Option vorgetragen -
     * sonst ginge das Pferd verloren, sobald man nur die Generationstiefe
     * ändert und erneut absendet.
     *
     * @param array{id: int|string, name: string, birth_year: mixed}|null $horse
     *   Bereits ausgewähltes Pferd zur Vorbelegung (oder null).
     */
    private static function searchFieldHtml(string $field, string $label, string $role, ?array $horse): string {
        $display = $horse !== null ? self::horseLabel($horse) : '';
        return '<div class="form-group">'
            . '<label for="' . $field . '_suche">' . $label . '</label>'
            . '<input type="text" id="' . $field . '_suche" class="form-control hv-pferdesuche"'
            . ' data-rolle="' . $role . '" data-ziel="' . $field . '"'
            . ' placeholder="Name eintippen und Vorschlag übernehmen …" autocomplete="off"'
            . ' value="' . htmlspecialchars($display, ENT_QUOTES, 'UTF-8') . '">'
            . '<select name="' . $field . '" id="' . $field . '" class="form-control" style="margin-top:0.4rem;">'
            . ($horse !== null
                ? '<option value="' . (int) $horse['id'] . '" selected>'
                    . htmlspecialchars($display, ENT_QUOTES, 'UTF-8') . '</option>'
                : '<option value="">– bitte oben suchen –</option>')
            . '</select>'
            . '</div>';
    }

    /** Anzeigetext der Vorbelegung - "Name (Jahrgang)", wie im Kern-Endpunkt. */
    private static function horseLabel(array $horse): string {
        return $horse['name']
            . (!empty($horse['birth_year']) ? ' (' . $horse['birth_year'] . ')' : '');
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
