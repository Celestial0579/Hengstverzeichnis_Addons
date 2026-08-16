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
            . 'style="display:inline-block;padding:0.5rem 1rem;background:var(--surface-muted);border-radius:var(--border-radius, 6px);text-decoration:none;color:inherit;">'
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
 * Bildschirm- UND Druck-Stylesheet. Bleibt als reine Druck-/Exportansicht
 * bewusst OHNE das PluginPage-Layout (Theming-Ausnahme, siehe Addons#66):
 * die Seite ist zum Drucken/PDF-Sichern gedacht und soll ohne Seiten-Chrome
 * auskommen. Bewusst ohne Zugriffsschutz: zeigt exakt
 * dieselben, bereits öffentlich über /horse?id=... einsehbaren Pedigree-Daten
 * in anderer Aufbereitung - keine zusätzliche Rechteausweitung.
 */
class ExportController extends BaseController {

    private const DEFAULT_DEPTH = 6;
    // Gedeckelt auf die Kern-Tiefe von /horse (6 Generationen): die Route ist
    // anonym erreichbar, eine größere wählbare Tiefe würde pro Request
    // exponentiell mehr Datenbank-Abfragen erlauben als der Kern selbst.
    private const MAX_DEPTH = 6;

    public function show(): void {
        // Öffentliche Sichtbarkeit exakt wie im Kern (PublicController::horseDetail):
        // Pferdedaten nur, wenn die Gast-Gruppe Leserecht hat - sonst wie nicht
        // vorhanden behandeln, damit dieser Export keine im Kern verborgenen Daten
        // preisgibt.
        if (!$this->hasPermission('horses', 'view')) {
            $this->renderNotFound('Für dieses Pferd konnte kein Stammbaum ermittelt werden.');
        }

        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $depth = isset($_GET['depth']) ? max(2, min(self::MAX_DEPTH, (int) $_GET['depth'])) : self::DEFAULT_DEPTH;

        // publishedOnly=true (ZWINGEND für öffentliche Ausgaben, siehe
        // PedigreeBuilder): ein unveröffentlichtes Wurzelpferd liefert damit null
        // (=> 404), unveröffentlichte Vorfahren erscheinen nicht im Export.
        $tree = PedigreeBuilder::build($id ?: null, $depth, true);
        if ($tree === null) {
            $this->renderNotFound('Für dieses Pferd konnte kein Stammbaum ermittelt werden.');
        }

        $title = (string) $tree['name'];
        $siteName = htmlspecialchars((string) ($this->settings['site_name'] ?? 'Hengstverzeichnis'), ENT_QUOTES, 'UTF-8');
        $generatedAt = htmlspecialchars(date('d.m.Y H:i'), ENT_QUOTES, 'UTF-8');

        // theming-ausnahme: druck-/pdf-ansicht bleibt bewusst ein eigenstaendiges dokument ohne seiten-chrome (Addons#66)
        echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><title>Stammbaum ' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>';
        echo '<link rel="stylesheet" href="/css/style.css">';
        echo <<<'HTML'
        <script>
        // Theme-Bootstrap wie im Framework-Layout (dort ausführlich begründet):
        // synchron im <head>, damit data-theme vor dem ersten Rendern steht.
        (function () {
            var stored = localStorage.getItem('theme');
            if (stored === 'dark' || stored === 'light') {
                document.documentElement.setAttribute('data-theme', stored);
            }
        })();
        </script>
        HTML;
        echo $this->styles();
        // theming-ausnahme: eigenes body der druckansicht, siehe marker am dokumentanfang
        echo '</head><body>';

        echo '<div class="toolbar no-print">';
        echo '<button type="button" onclick="window.print()">🖨️ Drucken / Als PDF speichern</button>';
        echo '<span>Tipp: Als Druckziel "Als PDF speichern" wählen, um eine PDF-Datei zu erhalten. Querformat wird empfohlen.</span>';
        echo '</div>';

        echo '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
        echo '<p class="meta">Stammbaum · ' . $siteName . ' · erzeugt am ' . $generatedAt . '</p>';

        echo '<div class="pedigree">';
        // nosemgrep: php.lang.security.injection.echoed-request.echoed-request
        // Falschbefund, geprueft: renderNode() baut das HTML selbst und
        // escaped JEDEN uebernommenen Wert mit htmlspecialchars(..., ENT_QUOTES)
        // - Name, Geburtsjahr, Lebensnummer (siehe dort). Ausgegeben wird
        // also fertiges, eigenes Markup und keine Anfragedaten. Semgrep folgt
        // dem Rueckgabewert bis zur Wurzel des Baums, die aus $_GET['id']
        // stammt, und meldet deshalb die Senke.
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
    /* theming-ausnahme: eigenständige Druck-/Exportansicht ohne Seiten-Chrome, kein PluginPage-Layout (Addons#66) */
    * { box-sizing: border-box; }
    body { font-family: sans-serif; padding: 1.5rem; color: var(--text-color); background: var(--bg-color); }
    h1 { margin: 0 0 0.2rem 0; }
    .meta { color: var(--text-muted); font-size: 0.9rem; margin: 0 0 1.5rem 0; }
    .toolbar { display: flex; align-items: center; gap: 1rem; margin-bottom: 1.5rem; padding: 0.8rem; background: var(--surface-muted); border-radius: var(--border-radius, 6px); }
    .toolbar button { padding: 0.5rem 1rem; font-size: 1rem; cursor: pointer; }
    .toolbar span { color: var(--text-muted); font-size: 0.85rem; }

    .pedigree { display: flex; overflow-x: auto; }
    .node { display: flex; align-items: center; }
    .box { border: 1px solid var(--border-color); border-radius: var(--border-radius, 6px); padding: 0.4rem 0.7rem; white-space: nowrap; background: var(--card-bg); text-align: center; min-width: 120px; }
    .box.placeholder { border-style: dashed; color: var(--text-muted); background: var(--surface-muted); }
    .box-name { font-weight: bold; font-size: 0.9rem; }
    .box-meta { font-size: 0.75rem; color: var(--text-muted); }
    .children { display: flex; flex-direction: column; justify-content: space-around; margin-left: 1.2rem; gap: 0.6rem; }
    .child { display: flex; align-items: center; }
    .child:empty { display: none; }

    @media print {
        .no-print { display: none !important; }
        /* theming-ausnahme: Druck-/PDF-Ansicht bleibt bewusst hell, auch wenn data-theme=dark aktiv ist. */
        body { padding: 0; background: #fff; color: #222; }
        .box { background: #fff; border-color: #999; }
        .box.placeholder { background: #f0f0f0; color: #555; }
        .meta, .box-meta { color: #555; }
        @page { size: A3 landscape; margin: 1cm; }
    }
</style>
CSS;
    }
}
