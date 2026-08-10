<?php
// genealogie-vergleich/Plugin.php
//
// Addon für Hengstverzeichnis_Framework: löst
// Celestial0579/Hengstverzeichnis_Addons#18. Stellt die Stammbäume zweier
// frei wählbarer Pferde nebeneinander dar und hebt Vorfahren-IDs, die in
// beiden Bäumen vorkommen (gemeinsame Blutlinie), farblich hervor.
//
// Nutzt App\Service\PedigreeBuilder::build() - laut
// docs/plugin-development.md im Framework-Repo explizit auch für Plugins
// unabhängig vom horse.detail_sections-Hook vorgesehen. Das Sammeln/
// Abgleichen der Vorfahren-IDs ist konzeptionell demselben Problem wie in
// plugins/inzuchtkoeffizient (CoiCalculator::collectAncestors()) - hier
// bewusst eine eigene, einfachere Variante (nur Mitgliedschaft in beiden
// Bäumen, keine Pfad-/Generationszählung nötig), da der Anwendungsfall rein
// visuell ist statt einen Koeffizienten zu berechnen.
//
// Installation (lokal im Framework-Repo):
//   cp -r genealogie-vergleich plugins/genealogie-vergleich
// Danach unter Admin -> Plugins verwalten (/admin/plugins) aktivieren.
//
// Siehe docs/plugin-development.md im Framework-Repo für die vollständige
// Hook-/Routen-/Berechtigungs-Referenz.

namespace Plugin\GenealogieVergleich;

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
     * Filter-Beispiel: verlinkt von der Detailseite eines Pferdes direkt auf
     * das Vergleichstool, mit diesem Pferd bereits als erste Auswahl
     * vorbelegt (Query-Parameter horse_a).
     */
    public function addDetailSection(array $sections, array $horse, array $horsePersons, ?array $pedigree): array {
        $horseId = (int) $horse['id'];
        $sections[] = '<p><a href="/plugin/genealogie-vergleich?horse_a=' . $horseId . '" '
            . 'style="display:inline-block;padding:0.5rem 1rem;background:var(--surface-muted);border-radius:var(--border-radius, 6px);text-decoration:none;color:inherit;">'
            . '🔬 Stammbaum mit einem anderen Pferd vergleichen</a></p>';
        return $sections;
    }

    /**
     * @return array<int, array{method:string, path:string, callback:array}>
     */
    public function routes(): array {
        return [
            [
                'method' => 'GET',
                'path' => '/',
                'callback' => [VergleichController::class, 'show'],
            ],
        ];
    }
}

/**
 * Öffentliches Vergleichstool - zeigt exakt dieselben, bereits über
 * /horse?id=... einsehbaren Pedigree-Daten zweier Pferde nur anders
 * (nebeneinander) aufbereitet, daher bewusst ohne Zugriffsschutz, analog zum
 * pedigree-export-Addon.
 */
class VergleichController extends BaseController {

    private const DEFAULT_DEPTH = 5;
    // Gedeckelt auf die Kern-Tiefe von /horse (6 Generationen): die Route ist
    // anonym erreichbar und baut zwei Bäume pro Request auf - eine größere
    // wählbare Tiefe würde exponentiell mehr Datenbank-Abfragen erlauben als
    // der Kern selbst.
    private const MAX_DEPTH = 6;

    public function show(): void {
        // Öffentliche Sichtbarkeit exakt wie im Kern (PublicController::horseDetail):
        // ohne Lese-Recht der Gast-Gruppe wird das Tool wie nicht vorhanden behandelt.
        if (!$this->hasPermission('horses', 'view')) {
            $this->renderNotFound('Nicht gefunden.');
        }

        // Nur veröffentlichte Pferde im Auswahl-Dropdown - sonst würden hier die
        // Namen aller (auch unveröffentlichter) Pferde an anonyme Besucher leaken.
        $horses = Database::getInstance()->query(
            'SELECT id, name, birth_year FROM horses WHERE deleted_at IS NULL AND is_published = 1 ORDER BY name ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $horseAId = !empty($_GET['horse_a']) ? (int) $_GET['horse_a'] : null;
        $horseBId = !empty($_GET['horse_b']) ? (int) $_GET['horse_b'] : null;
        $depth = isset($_GET['depth']) ? max(2, min(self::MAX_DEPTH, (int) $_GET['depth'])) : self::DEFAULT_DEPTH;

        // publishedOnly=true: unveröffentlichte Wurzelpferde/Vorfahren fließen nicht
        // in den Vergleich ein (ZWINGEND für öffentliche Ausgaben, siehe PedigreeBuilder).
        $treeA = $horseAId ? PedigreeBuilder::build($horseAId, $depth, true) : null;
        $treeB = $horseBId ? PedigreeBuilder::build($horseBId, $depth, true) : null;

        $commonIds = [];
        if ($treeA !== null && $treeB !== null) {
            $idsA = [];
            $this->collectIds($treeA, $idsA);
            $idsB = [];
            $this->collectIds($treeB, $idsB);
            $commonIds = array_intersect_key($idsA, $idsB);
        }

        // Die Seite rendert als Fragment im Framework-Layout
        // (App\Plugin\PluginPage, Addons#66) - Header, Navigation,
        // Theme-Umschalter, Markenfarben und style.css kommen zentral vom
        // Layout. Hier bleibt nur addon-spezifische Geometrie (Formular-
        // Raster, Stammbaum-Kästen), Farben ausschließlich über
        // Theme-Variablen.
        $content = $this->styles();
        $content .= '<div class="card">';
        $content .= '<h1>🔬 Genealogie-Vergleichstool</h1>';
        $content .= '<p>Vergleicht die Stammbäume zweier Pferde und hebt gemeinsame Vorfahren hervor.</p>';

        $content .= '<form method="GET">';
        $content .= '<div class="row">';
        $content .= '<div class="form-group"><label for="horse_a">Pferd A</label>' . $this->horseSelect($horses, 'horse_a', $horseAId) . '</div>';
        $content .= '<div class="form-group"><label for="horse_b">Pferd B</label>' . $this->horseSelect($horses, 'horse_b', $horseBId) . '</div>';
        $content .= '</div>';
        $content .= '<div class="form-group"><label for="depth">Generationstiefe (2-' . self::MAX_DEPTH . ')</label>';
        $content .= '<input type="number" name="depth" id="depth" class="form-control" min="2" max="' . self::MAX_DEPTH . '" value="' . (int) $depth . '"></div>';
        $content .= '<p><button type="submit" class="btn">Vergleichen</button></p>';
        $content .= '</form>';

        if ($treeA !== null && $treeB !== null) {
            $sharedCount = count($commonIds);
            $content .= '<p class="meta">' . ($sharedCount > 0
                ? "Gemeinsame Vorfahren gefunden: {$sharedCount} (gold hervorgehoben)."
                : 'Keine gemeinsamen Vorfahren innerhalb der gewählten Generationstiefe gefunden.') . '</p>';

            $content .= '<div class="comparison">';
            $content .= '<div class="pedigree-col"><h2>' . htmlspecialchars((string) $treeA['name'], ENT_QUOTES, 'UTF-8') . '</h2>'
                . '<div class="pedigree">' . $this->renderNode($treeA, $commonIds) . '</div></div>';
            $content .= '<div class="pedigree-col"><h2>' . htmlspecialchars((string) $treeB['name'], ENT_QUOTES, 'UTF-8') . '</h2>'
                . '<div class="pedigree">' . $this->renderNode($treeB, $commonIds) . '</div></div>';
            $content .= '</div>';
        } elseif ($horseAId || $horseBId) {
            $content .= '<p class="meta">Bitte beide Pferde auswählen, um den Vergleich zu sehen.</p>';
        }

        $content .= '</div>';

        \App\Plugin\PluginPage::render('Genealogie-Vergleich', $content);
    }

    /**
     * @param array<int, true> &$ids Sammlung gefundener, echter (nicht-Platzhalter) Vorfahren-IDs.
     */
    private function collectIds(?array $node, array &$ids): void {
        if ($node === null || empty($node['id']) || !empty($node['is_placeholder'])) {
            return;
        }
        $ids[(int) $node['id']] = true;
        $this->collectIds($node['sire'] ?? null, $ids);
        $this->collectIds($node['dam'] ?? null, $ids);
    }

    /**
     * @param array<int, true> $commonIds
     */
    private function renderNode(?array $node, array $commonIds): string {
        if ($node === null) {
            return '';
        }

        $isPlaceholder = !empty($node['is_placeholder']);
        $isShared = !$isPlaceholder && !empty($node['id']) && isset($commonIds[(int) $node['id']]);

        $name = htmlspecialchars((string) ($node['name'] ?? 'Unbekannt'), ENT_QUOTES, 'UTF-8');
        $year = !empty($node['birth_year']) ? htmlspecialchars((string) $node['birth_year'], ENT_QUOTES, 'UTF-8') : '';

        $classes = 'box' . ($isPlaceholder ? ' placeholder' : '') . ($isShared ? ' shared' : '');

        $html = '<div class="node"><div class="' . $classes . '">';
        $html .= '<div class="box-name">' . $name . '</div>';
        if ($year !== '') {
            $html .= '<div class="box-meta">' . $year . '</div>';
        }
        $html .= '</div>';

        $sire = $node['sire'] ?? null;
        $dam = $node['dam'] ?? null;
        if ($sire !== null || $dam !== null) {
            $html .= '<div class="children">';
            $html .= '<div class="child">' . $this->renderNode($sire, $commonIds) . '</div>';
            $html .= '<div class="child">' . $this->renderNode($dam, $commonIds) . '</div>';
            $html .= '</div>';
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * @param array<int, array<string, mixed>> $horses
     */
    private function horseSelect(array $horses, string $name, ?int $selected): string {
        $html = '<select name="' . $name . '" id="' . $name . '" class="form-control"><option value="">– auswählen –</option>';
        foreach ($horses as $h) {
            $isSelected = $selected === (int) $h['id'] ? ' selected' : '';
            $html .= '<option value="' . (int) $h['id'] . '"' . $isSelected . '>'
                . htmlspecialchars($h['name'] . ($h['birth_year'] ? ' (' . $h['birth_year'] . ')' : ''), ENT_QUOTES, 'UTF-8')
                . '</option>';
        }
        return $html . '</select>';
    }

    // Addon-spezifische Geometrie (zweispaltiges Formular-Raster, Stammbaum-
    // Kästen der Vergleichsansicht) - Grundstile für body/Formulare/Buttons
    // kommen seit Addons#66 zentral aus dem Layout.
    private function styles(): string {
        return <<<CSS
<style>
    .meta { color: var(--text-muted); }
    .row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }

    .comparison { display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; }
    .pedigree-col h2 { font-size: 1.1rem; }
    .pedigree { overflow-x: auto; }
    .node { display: flex; align-items: center; }
    .box { border: 1px solid var(--border-color); border-radius: var(--border-radius, 6px); padding: 0.4rem 0.7rem; white-space: nowrap; background: var(--card-bg); text-align: center; min-width: 100px; }
    .box.placeholder { border-style: dashed; color: var(--text-muted); background: var(--surface-muted); }
    .box.shared { border: 2px solid var(--warning-fg); background: var(--info-soft-bg); font-weight: bold; }
    .box-name { font-weight: bold; font-size: 0.85rem; }
    .box-meta { font-size: 0.7rem; color: var(--text-muted); }
    .children { display: flex; flex-direction: column; justify-content: space-around; margin-left: 1rem; gap: 0.5rem; }
    .child { display: flex; align-items: center; }
    .child:empty { display: none; }
</style>
CSS;
    }
}
