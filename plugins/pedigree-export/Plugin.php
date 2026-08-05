<?php
// pedigree-export/Plugin.php
//
// Addon für Hengstverzeichnis_Framework: löst
// Celestial0579/Hengstverzeichnis_Addons#7 (Bild-/PDF-Export für die
// Pedigree-Visualisierung) sowie den Pedigree-Teil von
// Celestial0579/Hengstverzeichnis_Addons#6. Umgesetzt als schlankes
// Druck-Stylesheet (@media print) für eine eigenständige Ansicht statt
// echter serverseitiger Bild-/PDF-Generierung - bewusste Design-Entscheidung,
// siehe README.md ("Warum kein echtes PDF-Rendering?"). Der Nutzer speichert
// über die Browser-Druckfunktion ("Ziel: Als PDF speichern") - keine neue
// Abhängigkeit nötig.
//
// Installation (lokal im Framework-Repo):
//   cp -r pedigree-export plugins/pedigree-export
// Danach unter Admin -> Plugins verwalten (/admin/plugins) aktivieren.
//
// Siehe docs/plugin-development.md im Framework-Repo für die vollständige
// Hook-/Routen-/Berechtigungs-Referenz.

namespace Plugin\PedigreeExport;

use App\Controllers\BaseController;
use App\Plugin\HookManager;
use App\Service\PedigreeBuilder;

class Plugin {

    public function register(HookManager $hooks): void {
        $hooks->addFilter('horse.detail_sections', [$this, 'addDetailSection']);
    }

    /**
     * Filter-Beispiel: hängt einen Link zur eigenen Druck-/Export-Ansicht an
     * die öffentliche Detailseite an. Öffnet bewusst in einem neuen Tab
     * (target="_blank"), damit die Detailseite selbst nicht verlassen wird.
     */
    public function addDetailSection(array $sections, array $horse, array $horsePersons, ?array $pedigree): array {
        $horseId = (int) $horse['id'];
        $sections[] = '<p><a href="/plugin/pedigree-export/export?id=' . $horseId . '" target="_blank" rel="noopener" '
            . 'style="display:inline-block;padding:0.5rem 1rem;background:#f8f9fa;border-radius:6px;text-decoration:none;color:inherit;">'
            . '🖨️ Stammbaum drucken / als PDF exportieren</a></p>';
        return $sections;
    }

    /**
     * @return array<int, array{method:string, path:string, callback:array}>
     */
    public function routes(): array {
        return [
            [
                'method' => 'GET',
                'path' => '/export',
                'callback' => [ExportController::class, 'show'],
            ],
        ];
    }
}

/**
 * Rendert eine komplett eigenständige HTML-Seite (kein Kern-Layout) mit
 * Bildschirm- UND Druck-Stylesheet. Bewusst ohne Zugriffsschutz: zeigt exakt
 * dieselben, bereits öffentlich über /hengst?id=... einsehbaren Pedigree-Daten
 * in anderer Aufbereitung - keine zusätzliche Rechteausweitung.
 */
class ExportController extends BaseController {

    private const DEFAULT_DEPTH = 6;
    private const MAX_DEPTH = 8;

    public function show(): void {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $depth = isset($_GET['depth']) ? max(2, min(self::MAX_DEPTH, (int) $_GET['depth'])) : self::DEFAULT_DEPTH;

        $tree = PedigreeBuilder::build($id ?: null, $depth);
        if ($tree === null) {
            $this->renderNotFound('Für dieses Pferd konnte kein Stammbaum ermittelt werden.');
        }

        $title = (string) $tree['name'];
        $siteName = htmlspecialchars((string) ($this->settings['site_name'] ?? 'Hengstverzeichnis'), ENT_QUOTES, 'UTF-8');
        $generatedAt = htmlspecialchars(date('d.m.Y H:i'), ENT_QUOTES, 'UTF-8');

        echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><title>Stammbaum ' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>';
        echo $this->styles();
        echo '</head><body>';

        echo '<div class="toolbar no-print">';
        echo '<button type="button" onclick="window.print()">🖨️ Drucken / Als PDF speichern</button>';
        echo '<span>Tipp: Als Druckziel "Als PDF speichern" wählen, um eine PDF-Datei zu erhalten. Querformat wird empfohlen.</span>';
        echo '</div>';

        echo '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
        echo '<p class="meta">Stammbaum · ' . $siteName . ' · erzeugt am ' . $generatedAt . '</p>';

        echo '<div class="pedigree">';
        echo $this->renderNode($tree);
        echo '</div>';

        echo '</body></html>';
    }

    private function renderNode(?array $node): string {
        if ($node === null) {
            return '';
        }

        $isPlaceholder = !empty($node['is_placeholder']);
        $name = htmlspecialchars((string) ($node['name'] ?? 'Unbekannt'), ENT_QUOTES, 'UTF-8');
        $year = !empty($node['birth_year']) ? htmlspecialchars((string) $node['birth_year'], ENT_QUOTES, 'UTF-8') : '';
        $ueln = !empty($node['ueln']) ? htmlspecialchars((string) $node['ueln'], ENT_QUOTES, 'UTF-8') : '';

        $html = '<div class="node">';
        $html .= '<div class="box' . ($isPlaceholder ? ' placeholder' : '') . '">';
        $html .= '<div class="box-name">' . $name . '</div>';
        if ($year !== '') {
            $html .= '<div class="box-meta">' . $year . '</div>';
        }
        if ($ueln !== '') {
            $html .= '<div class="box-meta">' . $ueln . '</div>';
        }
        $html .= '</div>';

        $sire = $node['sire'] ?? null;
        $dam = $node['dam'] ?? null;
        if ($sire !== null || $dam !== null) {
            $html .= '<div class="children">';
            $html .= '<div class="child">' . $this->renderNode($sire) . '</div>';
            $html .= '<div class="child">' . $this->renderNode($dam) . '</div>';
            $html .= '</div>';
        }

        $html .= '</div>';
        return $html;
    }

    private function styles(): string {
        return <<<CSS
<style>
    * { box-sizing: border-box; }
    body { font-family: sans-serif; padding: 1.5rem; color: #222; }
    h1 { margin: 0 0 0.2rem 0; }
    .meta { color: #666; font-size: 0.9rem; margin: 0 0 1.5rem 0; }
    .toolbar { display: flex; align-items: center; gap: 1rem; margin-bottom: 1.5rem; padding: 0.8rem; background: #f0f0f0; border-radius: 6px; }
    .toolbar button { padding: 0.5rem 1rem; font-size: 1rem; cursor: pointer; }
    .toolbar span { color: #555; font-size: 0.85rem; }

    .pedigree { display: flex; overflow-x: auto; }
    .node { display: flex; align-items: center; }
    .box { border: 1px solid #999; border-radius: 6px; padding: 0.4rem 0.7rem; white-space: nowrap; background: #fff; text-align: center; min-width: 120px; }
    .box.placeholder { border-style: dashed; color: #888; background: #fafafa; }
    .box-name { font-weight: bold; font-size: 0.9rem; }
    .box-meta { font-size: 0.75rem; color: #666; }
    .children { display: flex; flex-direction: column; justify-content: space-around; margin-left: 1.2rem; gap: 0.6rem; }
    .child { display: flex; align-items: center; }
    .child:empty { display: none; }

    @media print {
        .no-print { display: none !important; }
        body { padding: 0; }
        @page { size: A3 landscape; margin: 1cm; }
    }
</style>
CSS;
    }
}
