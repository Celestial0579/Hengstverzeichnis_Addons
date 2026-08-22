<?php
// merkliste/Plugin.php
//
// Addon für Hengstverzeichnis_Framework: löst
// Celestial0579/Hengstverzeichnis_Addons#19. Besucher ohne Account können
// sich beim Durchstöbern des Katalogs Favoriten merken - rein clientseitig
// (Pferde-IDs im localStorage des Browsers, kein Account, keine
// Server-Session, keine Cookies). Die eigene Seite /plugin/merkliste löst
// die gespeicherten IDs über eine schreibgeschützte JSON-API zu
// Name/Bild/Link auf.
//
// Ursprünglich war der "Merken"-Button nur auf der Detailseite geplant
// (Phase 1 der Hooks) - der Kern stellt inzwischen auch den
// catalog.card_sections-Filter bereit (#97), daher erscheint der Button
// zusätzlich direkt auf den Katalogkarten.
//
// Installation (lokal im Framework-Repo):
//   cp -r merkliste plugins/merkliste
// Danach unter Admin -> Plugins verwalten (/admin/plugins) aktivieren.
// Keine Berechtigung nötig - die API gibt ausschließlich Daten aus, die
// über den öffentlichen Katalog ohnehin sichtbar sind.
//
// Siehe docs/plugin-development.md im Framework-Repo für die vollständige
// Hook-/Routen-/Berechtigungs-Referenz.

namespace Plugin\Merkliste;

use App\Controllers\BaseController;
use App\Database;
use App\Helper\MediaUrl;
use App\Plugin\HookManager;
use App\Plugin\PluginPage;
use PDO;

class Plugin {

    /**
     * Sorgt dafür, dass das Script-Tag genau einmal je Request ausgegeben
     * wird (Addons#73): buttonHtml() läuft über catalog.card_sections für
     * JEDE Katalogkarte - vorher stand dadurch der komplette 3,8-KB-
     * Inline-Block 24-mal (CATALOG_PER_PAGE) im HTML einer Katalogseite.
     */
    private bool $scriptEmitted = false;

    public function register(HookManager $hooks): void {
        $hooks->addFilter('horse.detail_sections', [$this, 'addDetailSection']);
        $hooks->addFilter('catalog.card_sections', [$this, 'addCardSection']);
    }

    /**
     * Gemeinsames, mehrfach einbindbares Button-Snippet. Die clientseitige
     * Logik (localStorage lesen/schreiben, Buttons synchronisieren,
     * Katalog-Einstieg, MutationObserver für das AJAX-Nachladen) liegt als
     * statisches Asset in assets/merkliste.js und wird über die Plugin-Route
     * GET /plugin/merkliste/assets.js cachebar ausgeliefert (Addons#73);
     * hier wird nur noch einmal je Request das <script src=... defer>-Tag
     * angehängt.
     */
    private function buttonHtml(int $horseId, bool $compact): string {
        $style = $compact
            ? 'padding:0.25rem 0.6rem;font-size:0.8em;'
            : 'padding:0.5rem 1rem;';

        // Der window.-Guard im onclick deckt den kurzen Zeitraum ab, bevor
        // das defer-Skript geladen ist: ein Klick verpufft dann still statt
        // mit einem ReferenceError in der Konsole.
        $html = '<button type="button" data-hv-merkliste="' . $horseId . '" '
            . 'onclick="window.hvMerklisteToggle&&hvMerklisteToggle(this)" '
            . 'style="' . $style . 'margin-top:0.5rem;border:1px solid var(--warning-fg);background:var(--info-soft-bg);border-radius:var(--border-radius, 4px);cursor:pointer;">'
            . '☆ Merken</button>';

        if (!$compact) {
            // Als App-Schaltfläche statt nackter Browser-Link (#49): die Klassen
            // kommen aus dem Framework-CSS, das im Detailseiten-Kontext geladen
            // ist - damit stimmen auch die Theme-Farben im Darkmode (#48).
            $html .= ' <a href="/plugin/merkliste" class="btn btn-secondary" style="margin-left:0.5rem;padding:0.5rem 1rem;">Zur Merkliste</a>';
        }

        if (!$this->scriptEmitted) {
            $this->scriptEmitted = true;
            $html .= self::scriptTag();
        }

        return $html;
    }

    /**
     * <script>-Tag für das statische Merklisten-Skript. `defer` entkoppelt
     * das Laden vom HTML-Parsen; der ?v=-Parameter (mtime der Datei)
     * invalidiert den Browser-Cache bei jedem Deploy einer neuen Fassung,
     * ohne die max-age=86400-Cachebarkeit im Normalfall zu opfern. Der
     * Router matcht Pfade ohne Query-String, der Parameter stört die Route
     * also nicht.
     */
    private static function scriptTag(): string {
        $version = @filemtime(self::assetPath()) ?: 0;
        return '<script src="/plugin/merkliste/assets.js?v=' . $version . '" defer></script>';
    }

    public static function assetPath(): string {
        return __DIR__ . '/assets/merkliste.js';
    }

    /**
     * Filter-Beispiel: "Merken"-Button auf der öffentlichen Detailseite.
     */
    public function addDetailSection(array $sections, array $horse, array $horsePersons, ?array $pedigree): array {
        $sections[] = '<div style="margin-top:0.5rem;">' . $this->buttonHtml((int) $horse['id'], false) . '</div>';
        return $sections;
    }

    /**
     * Filter-Beispiel: kompakter "Merken"-Button auf jeder Katalogkarte
     * (catalog.card_sections, #97).
     */
    public function addCardSection(array $sections, array $horse): array {
        $sections[] = $this->buttonHtml((int) $horse['id'], true);
        return $sections;
    }

    /**
     * @return array<int, array{method:string, path:string, callback:array}>
     */
    public function routes(): array {
        return [
            ['method' => 'GET', 'path' => '/', 'callback' => [MerklisteController::class, 'show']],
            ['method' => 'GET', 'path' => '/api', 'callback' => [MerklisteController::class, 'api']],
            ['method' => 'GET', 'path' => '/assets.js', 'callback' => [MerklisteController::class, 'assetsJs']],
        ];
    }
}

/**
 * Öffentliche Merklisten-Seite samt schreibgeschützter JSON-API. Beide
 * Routen sind bewusst anonym erreichbar - die API löst nur IDs zu Daten auf,
 * die der öffentliche Katalog ohnehin zeigt, und unterliegt demselben Gating
 * (horses.view der Gast-Gruppe, nur veröffentlichte, nicht gelöschte
 * Pferde).
 */
class MerklisteController extends BaseController {

    private const MAX_IDS = 100;

    /**
     * Statisches Merklisten-Skript (Addons#73): assets/merkliste.js mit
     * JS-Content-Type und Cache-Control ausliefern, damit der Browser-Cache
     * greift - vorher stand derselbe Code als Inline-Block in jeder
     * Katalogkarte. Anonym erreichbar wie die übrigen Merklisten-Routen;
     * der Inhalt ist statischer Code ohne Daten.
     */
    public function assetsJs(): void {
        $path = Plugin::assetPath();
        if (!is_file($path)) {
            http_response_code(404);
            exit;
        }

        header('Content-Type: application/javascript; charset=utf-8');
        header('Cache-Control: public, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . (string) filesize($path));
        readfile($path);
        exit;
    }

    public function show(): void {
        // Inhalt als Fragment im Haupt-Layout über PluginPage (Addons#66):
        // Header, Navigation, Theme-Umschalter und Grund-Styling kommen vom
        // Framework, hier steht nur noch der eigentliche Seiteninhalt.
        // Addon-spezifisch bleibt allein die Geometrie der Merklisten-Zeilen
        // (Thumbnail-Raster) - Farben ausschließlich über Theme-Variablen.
        $content = '<style>
            .merkliste-row{display:flex;flex-wrap:wrap;gap:1rem;padding:1rem 0;border-bottom:1px solid var(--border-color);align-items:center;}
            .merkliste-row img{width:80px;height:80px;object-fit:cover;border-radius:var(--border-radius, 6px);}
            .merkliste-row h2{margin:0 0 0.3rem 0;font-size:1.05rem;}
            #leer{color:var(--text-muted);}
        </style>';
        $content .= '<div class="card">';
        $content .= '<h1>⭐ Meine Merkliste</h1>';
        $content .= '<p style="color:var(--text-muted);font-size:0.9em;">Die Merkliste wird nur in diesem Browser gespeichert (localStorage) - ohne Account, ohne Server-Speicherung.</p>';
        $content .= '<div id="liste"></div>';
        $content .= '<p id="leer" style="display:none;">Noch keine Pferde gemerkt. Im <a href="/katalog">Katalog</a> stöbern und "☆ Merken" klicken.</p>';

        $content .= '<script>
            (function () {
                function readIds() {
                    try {
                        var raw = JSON.parse(localStorage.getItem("hv_merkliste") || "[]");
                        return Array.isArray(raw) ? raw.map(Number).filter(function (n) { return n > 0; }) : [];
                    } catch (e) { return []; }
                }

                function render(horses) {
                    var liste = document.getElementById("liste");
                    var leer = document.getElementById("leer");
                    liste.textContent = "";
                    if (!horses.length) {
                        leer.style.display = "block";
                        return;
                    }
                    leer.style.display = "none";
                    horses.forEach(function (horse) {
                        var row = document.createElement("div");
                        row.className = "merkliste-row";

                        if (horse.image_url) {
                            var img = document.createElement("img");
                            img.src = horse.image_url;
                            img.alt = "";
                            row.appendChild(img);
                        }

                        var info = document.createElement("div");
                        var h2 = document.createElement("h2");
                        var link = document.createElement("a");
                        link.href = horse.url;
                        link.textContent = horse.name;
                        h2.appendChild(link);
                        info.appendChild(h2);
                        if (horse.birth_year) {
                            var year = document.createElement("div");
                            year.textContent = "Geburtsjahr: " + horse.birth_year;
                            info.appendChild(year);
                        }
                        row.appendChild(info);

                        var remove = document.createElement("button");
                        remove.className = "btn btn-secondary";
                        remove.style.color = "var(--danger-fg)";
                        remove.type = "button";
                        remove.textContent = "Entfernen";
                        remove.addEventListener("click", function () {
                            var ids = readIds().filter(function (id) { return id !== horse.id; });
                            localStorage.setItem("hv_merkliste", JSON.stringify(ids));
                            load();
                        });
                        row.appendChild(remove);

                        liste.appendChild(row);
                    });
                }

                function load() {
                    var ids = readIds();
                    if (!ids.length) {
                        render([]);
                        return;
                    }
                    fetch("/plugin/merkliste/api?ids=" + ids.join(","))
                        .then(function (res) { return res.json(); })
                        .then(render)
                        .catch(function () { render([]); });
                }

                load();
            })();
        </script>';
        $content .= '<p style="margin-top:2rem;"><a href="/katalog" class="btn btn-secondary">Zurück zum Katalog</a></p>';
        $content .= '</div>';

        PluginPage::render('Meine Merkliste', $content);
    }

    /**
     * Schreibgeschützte JSON-API: löst die per JS aus dem localStorage
     * gelesenen IDs zu Name/Bild/Link auf. Gleiche Sichtbarkeitsregeln wie
     * der öffentliche Katalog; unbekannte, unveröffentlichte und gelöschte
     * IDs fehlen schlicht in der Antwort.
     */
    public function api(): void {
        header('Content-Type: application/json; charset=utf-8');

        if (!$this->hasPermission('horses', 'view')) {
            echo json_encode([]);
            exit;
        }

        $ids = array_values(array_filter(array_map(
            'intval',
            explode(',', (string) ($_GET['ids'] ?? ''))
        ), static fn (int $id): bool => $id > 0));
        $ids = array_slice(array_unique($ids), 0, self::MAX_IDS);

        if (empty($ids)) {
            echo json_encode([]);
            exit;
        }

        // Die Platzhalterliste hat eine FESTE Laenge, abgeleitet allein aus
        // der Konstanten MAX_IDS - nicht aus der Anzahl der uebergebenen IDs.
        //
        // Vorher entstand sie aus count($ids), also aus einer Groesse, die an
        // $_GET haengt. Der Abfragetext wurde damit zur Laufzeit aus einem
        // Wert zusammengesetzt, dessen Herkunft die Eingabe ist. Dass dabei
        // nur "?,?,?" herauskommen KANN, muss man wissen - man sieht es dem
        // Code nicht an, und eine statische Analyse kann es nicht wissen.
        //
        // Mit fester Laenge ist der Abfragetext ueber alle Aufrufe hinweg
        // identisch und enthaelt keinen abgeleiteten Wert mehr. Die Liste wird
        // mit 0 aufgefuellt; IDs sind oben auf > 0 gefiltert, 0 trifft also
        // keine Zeile. Der Index auf der Primaerschluesselspalte bleibt
        // nutzbar, und die Zahl gebundener Parameter ist mit 100 konstant.
        $platzhalter = rtrim(str_repeat('?,', self::MAX_IDS), ',');
        $gebunden = array_pad($ids, self::MAX_IDS, 0);

        $stmt = Database::getInstance()->prepare(
            "SELECT id, name, birth_year, image_url
             FROM horses
             WHERE id IN ({$platzhalter}) AND deleted_at IS NULL AND is_published = 1"
        );
        $stmt->execute($gebunden);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // In der vom Besucher gemerkten Reihenfolge ausgeben.
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'birth_year' => $row['birth_year'] !== null ? (int) $row['birth_year'] : null,
                // Schon im JSON die geschützte Adresse, nicht der rohe
                // Spaltenwert: Das JS setzt den Wert unbesehen als img.src.
                // Stünde hier der Speicherort (/uploads/horses/<datei>), wäre
                // der Dateiname über die öffentliche API bekannt - und die
                // Datei bliebe nach einer Depublikation abrufbar.
                'image_url' => MediaUrl::horseImage($row),
                'url' => '/horse?id=' . (int) $row['id'],
            ];
        }

        $result = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $result[] = $byId[$id];
            }
        }

        echo json_encode($result);
        exit;
    }
}
