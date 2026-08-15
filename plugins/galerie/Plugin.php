<?php
// galerie/Plugin.php
//
// Addon für Hengstverzeichnis_Framework: löst
// Celestial0579/Hengstverzeichnis_Addons#16. `horses` hat genau ein
// `image_url`-Feld - dieses Addon ergänzt eine Medien-Galerie pro Pferd:
// mehrere hochgeladene Fotos (gleiches Upload-/Validierungsmuster wie das
// bestehende image_url-Feld) sowie Videos als externer Embed-Link
// (YouTube/Vimeo) statt Eigen-Hosting - eigenes Video-Hosting/Transcoding
// wäre ein erheblicher Mehraufwand und passt nicht zur "keine externen
// Abhängigkeiten"-Philosophie des Kerns.
//
// Videos werden bewusst als Link in neuem Tab geöffnet statt als
// eingebettetes iframe: die Content-Security-Policy des Kerns
// (config/config.php, default-src 'self' ohne frame-src-Ausnahme) blockiert
// fremde iframes - ein Embed würde beim Besucher lautlos leer bleiben.
//
// Installation (lokal im Framework-Repo):
//   cp -r galerie plugins/galerie
// Danach unter Admin -> Plugins verwalten (/admin/plugins) aktivieren und
// der gewünschten Gruppe unter /admin/groups die Berechtigung
// "Galerie -> Verwalten" zuweisen.
//
// Siehe docs/plugin-development.md im Framework-Repo für die vollständige
// Hook-/Routen-/Berechtigungs-Referenz.

namespace Plugin\Galerie;

use App\Controllers\BaseController;
use App\Database;
use App\Plugin\HookManager;
use App\Plugin\PluginPage;
use App\Router;
use PDO;

class Plugin {

    public function register(HookManager $hooks): void {
        $this->ensureTable();
        $hooks->addFilter('horse.detail_sections', [$this, 'addDetailSection']);
        $hooks->addFilter('horse.edit_sections', [$this, 'addEditSection']);
        $hooks->addFilter('admin.dashboard_tiles', [$this, 'addDashboardTile']);
    }

    /**
     * Filter (#88, Framework#255): hängt die Medienpflege direkt in das
     * Admin-Bearbeitungsformular des Hengstes.
     *
     * Anders als bei #87 geht es hier NICHT um Performance - die
     * Pferdeauswahl läuft seit `5fe4c1c` bereits über eine begrenzte
     * AJAX-Suche. Es geht um dasselbe strukturelle Muster: Wer Medien zu EINEM
     * Pferd pflegt, öffnet dafür bisher eine bestandsweite Verwaltungsseite
     * und sucht das Pferd dort erneut heraus, obwohl er längst in dessen
     * Datensatz steht.
     *
     * Drei Dinge unterscheiden diesen Abschnitt von dem in #87:
     *
     * - **`enctype="multipart/form-data"`.** Der Abschnitt bringt sein eigenes
     *   Formular mit (der Hook setzt es ausserhalb des Kern-Formulars ab), es
     *   muss die Kodierung also selbst deklarieren - sonst käme der Upload als
     *   leeres $_FILES an, und zwar ohne Fehlermeldung.
     * - **Zwei einander ausschliessende Medienarten.** Bild ODER Video-Link.
     *   Der Text sagt das ausdrücklich, weil `store()` bei beidem den Upload
     *   gewinnen lässt und der Video-Link stillschweigend verfiele.
     * - **Keine Lightbox.** Die öffentliche Detailseite bindet dafür JS und CSS
     *   ein; im Bearbeitungsformular wäre sie funktionslos. Die Vorschau bleibt
     *   ein einfaches Vorschaubild.
     *
     * Auf einem Kern ohne den Hook passiert schlicht nichts; die
     * Verwaltungsseite bleibt deshalb als Pflegeweg bestehen und dient
     * weiterhin der bestandsweiten Übersicht.
     */
    public function addEditSection(array $sections, array $horse): array {
        // Das Bearbeitungsformular verlangt horses.edit, diese Daten aber
        // galerie.manage. Ohne diese Prüfung sähe ein Redakteur ein Formular,
        // das beim Absenden 403 liefert. Fail-closed, wie in #87.
        if (!\App\Permission\GroupMembership::hasPermission(
            (int) ($_SESSION['user_id'] ?? 0), 'galerie', 'manage'
        )) {
            return $sections;
        }

        $horseId = (int) ($horse['id'] ?? 0);
        if ($horseId <= 0) {
            return $sections;
        }

        $stmt = Database::getInstance()->prepare(
            'SELECT id, type, file_path, video_url, caption, sort_order
             FROM `plugin_galerie_media`
             WHERE horse_id = :id
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['id' => $horseId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $csrfToken = Router::generateCsrfToken();
        $esc = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $html = '<h3 style="margin-top:0;">🖼️ Galerie</h3>';

        if ($rows) {
            $html .= '<table style="width:100%;border-collapse:collapse;margin-bottom:1rem;">';
            $html .= '<thead><tr style="text-align:left;border-bottom:2px solid var(--border-color);">'
                . '<th>Vorschau</th><th>Art</th><th>Bildunterschrift</th><th>Reihenfolge</th><th></th></tr></thead><tbody>';
            foreach ($rows as $row) {
                $html .= '<tr style="border-bottom:1px solid var(--border-color);"><td style="padding:0.4rem 0;">';
                if ($row['type'] === 'image' && !empty($row['file_path'])) {
                    // Bewusst ohne Lightbox: Sie hängt an JS/CSS der
                    // öffentlichen Detailseite und wäre hier funktionslos.
                    $html .= '<img src="' . $esc($row['file_path']) . '" alt="" loading="lazy" decoding="async"'
                        . ' style="width:64px;height:64px;object-fit:cover;border-radius:var(--border-radius, 4px);border:1px solid var(--border-color);">';
                } else {
                    $html .= '<span style="font-size:1.5rem;" aria-hidden="true">🎬</span>';
                }
                $html .= '</td><td>' . ($row['type'] === 'image' ? 'Bild' : 'Video') . '</td>'
                    . '<td>' . $esc($row['caption'] ?? '–') . '</td>'
                    . '<td>' . (int) $row['sort_order'] . '</td>'
                    . '<td><form method="POST" action="/plugin/galerie/verwaltung/delete" style="margin:0;"'
                    . ' onsubmit="return confirm(\'Medium wirklich löschen? Eine hochgeladene Datei wird dabei mit entfernt.\');">'
                    . '<input type="hidden" name="csrf_token" value="' . $esc($csrfToken) . '">'
                    . '<input type="hidden" name="id" value="' . (int) $row['id'] . '">'
                    . '<input type="hidden" name="horse_id" value="' . $horseId . '">'
                    . '<input type="hidden" name="zurueck" value="pferd">'
                    . '<button type="submit" class="btn btn-secondary" style="color:var(--danger-fg);">Löschen</button>'
                    . '</form></td></tr>';
            }
            $html .= '</tbody></table>';
        } else {
            $html .= '<p style="color:var(--text-muted);">Für dieses Pferd sind noch keine Medien erfasst.</p>';
        }

        // enctype ist hier NICHT optional - siehe Methodenkommentar.
        $html .= '<form method="POST" action="/plugin/galerie/verwaltung/store" enctype="multipart/form-data">';
        $html .= '<input type="hidden" name="csrf_token" value="' . $esc($csrfToken) . '">';
        $html .= '<input type="hidden" name="horse_id" value="' . $horseId . '">';
        $html .= '<input type="hidden" name="zurueck" value="pferd">';
        $html .= '<p style="color:var(--text-muted);font-size:0.85rem;margin-top:0;">'
            . 'Entweder eine Bilddatei hochladen <strong>oder</strong> einen Video-Link angeben. '
            . 'Wird beides ausgefüllt, gewinnt der Upload und der Link wird verworfen.</p>';
        $html .= '<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">';
        $html .= '<div class="form-group"><label for="gal_image">Bilddatei (max. 5 MB)</label>'
            . '<input type="file" name="image" id="gal_image" class="form-control" accept="image/jpeg,image/png,image/webp,image/gif"></div>';
        $html .= '<div class="form-group"><label for="gal_video">Video-Link</label>'
            . '<input type="url" name="video_url" id="gal_video" class="form-control" placeholder="https://www.youtube.com/watch?v=…"></div>';
        $html .= '</div>';
        $html .= '<div style="display:grid;grid-template-columns:2fr 1fr;gap:1rem;">';
        $html .= '<div class="form-group"><label for="gal_caption">Bildunterschrift</label>'
            . '<input type="text" name="caption" id="gal_caption" class="form-control" maxlength="255"></div>';
        $html .= '<div class="form-group"><label for="gal_sort">Reihenfolge</label>'
            . '<input type="number" name="sort_order" id="gal_sort" class="form-control" value="' . (count($rows) * 10) . '"></div>';
        $html .= '</div>';
        // Beschriftung bewusst nicht "Speichern": Auf der Seite gibt es zwei
        // Knöpfe, und wer hier drückt, verliert ungespeicherte Stammdaten oben.
        $html .= '<p><button type="submit" class="btn">Medium hinzufügen</button>'
            . ' <span style="color:var(--text-muted);font-size:0.85rem;">Änderungen an den Stammdaten oben bitte zuerst speichern.</span></p>';
        $html .= '</form>';

        $sections[] = $html;
        return $sections;
    }

    /**
     * Framework-Hook (#75): Der PluginManager ruft install() bei der
     * Aktivierung und nach einem Addon-Update genau einmal auf - das
     * DDL-Statement läuft damit nicht mehr in jedem Request.
     */
    public function install(): void {
        Database::getInstance()->exec(
            'CREATE TABLE IF NOT EXISTS `plugin_galerie_media` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `horse_id` INT NOT NULL,
                `type` ENUM(\'image\',\'video\') NOT NULL,
                `file_path` VARCHAR(255) NULL DEFAULT NULL,
                `video_url` VARCHAR(255) NULL DEFAULT NULL,
                `caption` VARCHAR(255) NULL DEFAULT NULL,
                `sort_order` INT NOT NULL DEFAULT 0,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`horse_id`) REFERENCES `horses`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /**
     * Fallback für ältere Kerne ohne install()-Hook (#75) - bewusst OHNE
     * Marker-Datei: Der Kern gibt das Plugin-Verzeichnis über einen
     * Inhalts-Fingerabdruck frei, in den auch Dotfiles einfließen. Jede zur
     * Laufzeit dorthin geschriebene Datei änderte den Fingerabdruck, und der
     * Kern deaktivierte das Plugin als unfreigegeben verändert. Statt DDL
     * pro Request (siehe Issue) läuft deshalb nur noch eine billige
     * SELECT-Probe je Request; erst wenn sie fehlschlägt, legt install()
     * die Tabelle an. Auf Kernen mit install()-Hook existiert die Tabelle
     * ohnehin - dort bleibt es bei der Probe.
     */
    private function ensureTable(): void {
        static $checked = false;
        if ($checked) {
            return;
        }
        try {
            Database::getInstance()->query('SELECT 1 FROM `plugin_galerie_media` LIMIT 1');
        } catch (\Throwable $e) {
            $this->install();
        }
        $checked = true;
    }

    /**
     * Erlaubte Video-Hosts: nur bekannte Plattformen, ausschließlich https.
     * Rückgabe ist die normalisierte URL oder null (Eingabe verworfen).
     */
    public static function sanitizeVideoUrl(string $url): ?string {
        $url = trim($url);
        $parts = parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return null;
        }

        $host = strtolower($parts['host']);
        $allowedHosts = ['www.youtube.com', 'youtube.com', 'youtu.be', 'vimeo.com', 'www.vimeo.com'];
        if (!in_array($host, $allowedHosts, true)) {
            return null;
        }

        return $url;
    }

    /**
     * Filter-Beispiel: Galerie-Grid mit schlanker Lightbox (reines
     * Inline-CSS/JS, keine externe Bibliothek) auf der öffentlichen
     * Detailseite. Zeigt nichts an, wenn keine Medien erfasst sind.
     */
    public function addDetailSection(array $sections, array $horse, array $horsePersons, ?array $pedigree): array {
        $stmt = Database::getInstance()->prepare(
            'SELECT type, file_path, video_url, caption
             FROM `plugin_galerie_media`
             WHERE horse_id = :id
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['id' => (int) $horse['id']]);
        $media = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($media)) {
            return $sections;
        }

        $html = '<div style="margin-top:0.5rem;">';
        $html .= '<h3 style="margin-bottom:0.5rem;">🖼️ Galerie</h3>';
        $html .= '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:0.6rem;">';

        foreach ($media as $item) {
            $caption = htmlspecialchars((string) ($item['caption'] ?? ''), ENT_QUOTES, 'UTF-8');
            if ($item['type'] === 'image' && !empty($item['file_path'])) {
                $src = htmlspecialchars((string) $item['file_path'], ENT_QUOTES, 'UTF-8');
                $html .= '<figure style="margin:0;">'
                    . '<img src="' . $src . '" alt="' . $caption . '" loading="lazy" '
                    . 'style="width:100%;height:120px;object-fit:cover;border-radius:var(--border-radius, 6px);cursor:zoom-in;" '
                    . 'onclick="hvGalerieLightbox(this.src, this.alt)">'
                    . ($caption !== '' ? '<figcaption style="font-size:0.8em;color:var(--text-muted);">' . $caption . '</figcaption>' : '')
                    . '</figure>';
            } elseif ($item['type'] === 'video' && !empty($item['video_url'])) {
                $url = htmlspecialchars((string) $item['video_url'], ENT_QUOTES, 'UTF-8');
                $html .= '<a href="' . $url . '" target="_blank" rel="noopener noreferrer" '
                    . 'style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:120px;'
                    . 'background:var(--surface-muted);color:var(--text-color);border-radius:var(--border-radius, 6px);text-decoration:none;text-align:center;padding:0.4rem;">'
                    . '<span style="font-size:1.8rem;">▶</span>'
                    . '<span style="font-size:0.8em;">' . ($caption !== '' ? $caption : 'Video ansehen') . '</span>'
                    . '</a>';
            }
        }

        $html .= '</div>';

        // Schlanke Lightbox: Overlay-DIV, Schließen per Klick/Escape.
        /* theming-ausnahme: Lightbox-Scrim bleibt in beiden Themes bewusst
           dunkel (rgba(0,0,0,0.85)) - er soll das Bild abgedunkelt
           freistellen, nicht der Flächenfarbe des Themes folgen. */
        $html .= '<div id="hv-galerie-lightbox" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.85);'
            . 'z-index:1000;align-items:center;justify-content:center;cursor:zoom-out;" onclick="this.style.display=\'none\'">'
            . '<img id="hv-galerie-lightbox-img" src="" alt="" style="max-width:92vw;max-height:92vh;border-radius:var(--border-radius, 6px);">'
            . '</div>';
        $html .= '<script>
            function hvGalerieLightbox(src, alt) {
                var overlay = document.getElementById("hv-galerie-lightbox");
                var img = document.getElementById("hv-galerie-lightbox-img");
                img.src = src;
                img.alt = alt || "";
                overlay.style.display = "flex";
            }
            document.addEventListener("keydown", function (e) {
                if (e.key === "Escape") {
                    var overlay = document.getElementById("hv-galerie-lightbox");
                    if (overlay) overlay.style.display = "none";
                }
            });
        </script>';
        $html .= '</div>';

        $sections[] = $html;
        return $sections;
    }

    public function addDashboardTile(array $tiles): array {
        $tiles[] = [
            'url' => '/plugin/galerie/verwaltung',
            'label' => 'Galerie',
            'icon' => '🖼️',
        ];
        return $tiles;
    }

    /**
     * @return array<int, array{module:string, action:string, label:string, module_label:string}>
     */
    public function permissions(): array {
        return [
            [
                'module' => 'galerie',
                'action' => 'manage',
                'label' => 'Verwalten',
                'module_label' => 'Galerie',
            ],
        ];
    }

    /**
     * @return array<int, array{method:string, path:string, callback:array}>
     */
    public function routes(): array {
        return [
            ['method' => 'GET', 'path' => '/verwaltung', 'callback' => [VerwaltungController::class, 'index']],
            // Serverseitige Pferdesuche für die Datalist im Formular (#74,
            // Muster Framework-Katalog): JSON, max. 50 Treffer, nur mit
            // galerie.manage (Konstruktor-Schutz des Controllers).
            ['method' => 'GET', 'path' => '/suche', 'callback' => [VerwaltungController::class, 'suche']],
            ['method' => 'POST', 'path' => '/verwaltung/store', 'callback' => [VerwaltungController::class, 'store']],
            ['method' => 'POST', 'path' => '/verwaltung/delete', 'callback' => [VerwaltungController::class, 'delete']],
        ];
    }
}

/**
 * Admin-Verwaltung der Galerie-Medien. Zugriffsschutz über die selbst
 * registrierte Berechtigung "galerie.manage", analog zum
 * zuchtschau-ergebnisse-Muster.
 */
class VerwaltungController extends BaseController {

    /** Treffer-Deckel der Datalist-Suche (#74). */
    private const SEARCH_LIMIT = 50;

    /** Medien je Verwaltungsseite (#74). */
    private const MEDIA_PER_PAGE = 50;

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
        $this->requirePermission('galerie', 'manage');
    }

    public function index(): void {
        $db = Database::getInstance();

        // Medienliste paginiert (#74): vorher lud die Seite die komplette
        // Medientabelle per JOIN ohne LIMIT und renderte jede Zeile ins HTML.
        $totalMedia = (int) $db->query('SELECT COUNT(*) FROM `plugin_galerie_media`')->fetchColumn();
        $pageCount = max(1, (int) ceil($totalMedia / self::MEDIA_PER_PAGE));
        $page = min($pageCount, max(1, (int) ($_GET['seite'] ?? 1)));

        $mediaStmt = $db->prepare(
            'SELECT m.*, h.name AS horse_name
             FROM `plugin_galerie_media` m
             JOIN horses h ON h.id = m.horse_id
             ORDER BY h.name ASC, m.sort_order ASC, m.id ASC
             LIMIT :limit OFFSET :offset'
        );
        $mediaStmt->bindValue('limit', self::MEDIA_PER_PAGE, PDO::PARAM_INT);
        $mediaStmt->bindValue('offset', ($page - 1) * self::MEDIA_PER_PAGE, PDO::PARAM_INT);
        $mediaStmt->execute();
        $media = $mediaStmt->fetchAll(PDO::FETCH_ASSOC);

        $csrfToken = Router::generateCsrfToken();

        // Die Seite rendert als Fragment im Framework-Layout
        // (App\Plugin\PluginPage, Addons#66) - Header, Navigation,
        // Theme-Umschalter, Markenfarben und style.css kommen zentral vom
        // Layout. Hier bleibt nur addon-spezifische Geometrie
        // (Formular-Raster, Vorschau-Thumbnails), Farben ausschließlich
        // über Theme-Variablen.
        $content = '<style>';
        $content .= '.galerie-row{display:grid;grid-template-columns:1fr 1fr;gap:1rem;}';
        $content .= '.galerie-hint{color:var(--text-muted);font-size:0.85em;margin-top:0.3rem;}';
        $content .= '.galerie-thumb{width:60px;height:45px;object-fit:cover;border-radius:var(--border-radius, 6px);}';
        $content .= '</style>';

        $content .= '<div class="card">';
        $content .= '<h1>🖼️ Foto-/Video-Galerie verwalten</h1>';

        $content .= '<h2>Medium hinzufügen</h2>';
        $content .= '<form method="POST" action="/plugin/galerie/verwaltung/store" enctype="multipart/form-data">';
        $content .= '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') . '">';

        // Pferde-Auswahl als Suchfeld mit serverseitig nachgeladener
        // Vorschlagsliste statt eines Voll-<select> über den gesamten
        // Bestand (#74, Muster Framework-Katalog). Die gewählte ID landet
        // per JS im Hidden-Feld horse_id; ohne JavaScript löst store() den
        // getippten Text über resolveHorseId() auf.
        $content .= '<div class="form-group"><label for="horse_q">Pferd</label>'
            . '<input type="text" name="horse_q" id="horse_q" class="form-control" list="horse_q_liste" autocomplete="off"'
            . ' placeholder="Namen eintippen und Vorschlag auswählen …" required>'
            . '<datalist id="horse_q_liste"></datalist>'
            . '<input type="hidden" name="horse_id" id="horse_id" value="">'
            . '</div>';

        $content .= '<div class="galerie-row">';
        $content .= '<div class="form-group"><label for="image">Foto hochladen (JPEG/PNG/WebP, max. 5 MB)</label>'
            . '<input type="file" name="image" id="image" class="form-control" accept="image/jpeg,image/png,image/webp"></div>';
        $content .= '<div class="form-group"><label for="video_url">ODER Video-Link (YouTube/Vimeo, https)</label>'
            . '<input type="url" name="video_url" id="video_url" class="form-control" placeholder="https://www.youtube.com/watch?v=..."></div>';
        $content .= '</div>';
        $content .= '<p class="galerie-hint">Genau eines von beiden angeben. Videos werden bewusst nur als externer Link eingebunden (kein Eigen-Hosting).</p>';

        $content .= '<div class="galerie-row">';
        $content .= '<div class="form-group"><label for="caption">Bildunterschrift (optional)</label><input type="text" name="caption" id="caption" class="form-control" maxlength="255"></div>';
        $content .= '<div class="form-group"><label for="sort_order">Sortierung (kleinere Zahl zuerst)</label><input type="number" name="sort_order" id="sort_order" class="form-control" value="0"></div>';
        $content .= '</div>';

        $content .= '<p><button type="submit" class="btn">Hinzufügen</button></p>';
        $content .= '</form>';

        // Progressive Enhancement der Pferdesuche: lädt Vorschläge von
        // /plugin/galerie/suche und mappt das gewählte Label auf die ID im
        // Hidden-Feld. Ohne fetch()/JS greift der No-JS-Fallback in store().
        $content .= '<script>
(function () {
    var input = document.getElementById("horse_q");
    var hidden = document.getElementById("horse_id");
    var list = document.getElementById("horse_q_liste");
    if (!input || !hidden || !list || typeof window.fetch !== "function") { return; }

    var byLabel = {};
    var timer = null;

    function sync() {
        hidden.value = Object.prototype.hasOwnProperty.call(byLabel, input.value)
            ? String(byLabel[input.value])
            : "";
    }

    function loadSuggestions() {
        var q = input.value.trim();
        if (q === "") { return; }
        fetch("/plugin/galerie/suche?q=" + encodeURIComponent(q))
            .then(function (res) { return res.json(); })
            .then(function (items) {
                if (!Array.isArray(items)) { return; }
                byLabel = {};
                list.textContent = "";
                items.forEach(function (item) {
                    byLabel[item.label] = item.id;
                    var option = document.createElement("option");
                    option.value = item.label;
                    list.appendChild(option);
                });
                sync();
            })
            .catch(function () { /* Suche nicht erreichbar - der No-JS-Fallback greift beim Absenden */ });
    }

    input.addEventListener("input", function () {
        sync();
        if (timer) { clearTimeout(timer); }
        timer = setTimeout(loadSuggestions, 200);
    });
    input.addEventListener("change", sync);
})();
</script>';

        $content .= '<h2>Erfasste Medien</h2>';
        $content .= '<table><thead><tr><th>Pferd</th><th>Typ</th><th>Vorschau/Link</th><th>Bildunterschrift</th><th>Sortierung</th><th></th></tr></thead><tbody>';
        foreach ($media as $row) {
            $content .= '<tr>';
            $content .= '<td>' . htmlspecialchars((string) $row['horse_name'], ENT_QUOTES, 'UTF-8') . '</td>';
            $content .= '<td>' . ($row['type'] === 'image' ? 'Foto' : 'Video') . '</td>';
            $content .= '<td>';
            if ($row['type'] === 'image' && !empty($row['file_path'])) {
                $content .= '<img class="galerie-thumb" src="' . htmlspecialchars((string) $row['file_path'], ENT_QUOTES, 'UTF-8') . '" alt="">';
            } elseif (!empty($row['video_url'])) {
                $url = htmlspecialchars((string) $row['video_url'], ENT_QUOTES, 'UTF-8');
                $content .= '<a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . $url . '</a>';
            }
            $content .= '</td>';
            $content .= '<td>' . htmlspecialchars((string) ($row['caption'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
            $content .= '<td>' . (int) $row['sort_order'] . '</td>';
            $content .= '<td><form method="POST" action="/plugin/galerie/verwaltung/delete" style="margin:0;" onsubmit="return confirm(\'Medium wirklich entfernen?\');">'
                . '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') . '">'
                . '<input type="hidden" name="seite" value="' . $page . '">'
                . '<input type="hidden" name="id" value="' . (int) $row['id'] . '">'
                . '<button type="submit" class="btn btn-secondary" style="color:var(--danger-fg);">Entfernen</button></form></td>';
            $content .= '</tr>';
        }
        if (empty($media)) {
            $content .= '<tr><td colspan="6">Noch keine Medien erfasst.</td></tr>';
        }
        $content .= '</tbody></table>';

        // Blätter-Leiste (#74): erscheint erst, wenn es mehr als eine Seite
        // gibt - die Ein-Seiten-Ansicht bleibt unverändert schlank.
        if ($pageCount > 1) {
            $content .= '<p class="galerie-hint">';
            if ($page > 1) {
                $content .= '<a class="btn btn-secondary" href="/plugin/galerie/verwaltung?seite=' . ($page - 1) . '">&laquo; Zurück</a> ';
            }
            $content .= 'Seite ' . $page . ' von ' . $pageCount . ' (' . $totalMedia . ' Medien)';
            if ($page < $pageCount) {
                $content .= ' <a class="btn btn-secondary" href="/plugin/galerie/verwaltung?seite=' . ($page + 1) . '">Weiter &raquo;</a>';
            }
            $content .= '</p>';
        }

        $content .= '<p><a href="/admin" class="btn btn-secondary">Zurück zum Dashboard</a></p>';
        $content .= '</div>';

        PluginPage::render('Galerie verwalten', $content);
    }

    public function store(): void {
        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }

        $db = Database::getInstance();

        // ID aus dem Hidden-Feld (per JS gesetzt), sonst No-JS-Fallback:
        // den getippten Text des Suchfelds serverseitig auflösen (#74). In
        // beiden Fällen wird gegen den Bestand geprüft - eine frei erfundene
        // ID liefe sonst in den FOREIGN-KEY-Fehler statt in einen Redirect.
        $horseId = !empty($_POST['horse_id']) ? (int) $_POST['horse_id'] : null;
        if ($horseId !== null) {
            $stmt = $db->prepare('SELECT id FROM horses WHERE id = ? AND deleted_at IS NULL');
            $stmt->execute([$horseId]);
            $horseId = $stmt->fetchColumn() !== false ? $horseId : null;
        } else {
            $horseQ = trim($_POST['horse_q'] ?? '');
            if ($horseQ !== '') {
                $horseId = $this->resolveHorseId($db, $horseQ);
            }
        }

        $videoUrl = trim($_POST['video_url'] ?? '');

        if ($horseId) {
            $imagePath = $this->handleImageUpload($_FILES['image'] ?? null);
            $safeVideoUrl = $videoUrl !== '' ? Plugin::sanitizeVideoUrl($videoUrl) : null;

            // Genau eine Medienquelle pro Eintrag: ein Upload gewinnt vor
            // einem gleichzeitig angegebenen Video-Link.
            $type = null;
            if ($imagePath !== null) {
                $type = 'image';
                $safeVideoUrl = null;
            } elseif ($safeVideoUrl !== null) {
                $type = 'video';
            }

            if ($type !== null) {
                $stmt = Database::getInstance()->prepare(
                    'INSERT INTO `plugin_galerie_media` (horse_id, type, file_path, video_url, caption, sort_order)
                     VALUES (:horse_id, :type, :file_path, :video_url, :caption, :sort_order)'
                );
                $stmt->execute([
                    'horse_id' => $horseId,
                    'type' => $type,
                    'file_path' => $imagePath,
                    'video_url' => $safeVideoUrl,
                    'caption' => trim($_POST['caption'] ?? '') ?: null,
                    'sort_order' => (int) ($_POST['sort_order'] ?? 0),
                ]);
            }
        }

        // Zurueck dorthin, wo die Pflege stattfand (#88): Aus dem
        // Bearbeitungsformular des Hengstes heraus in die bestandsweite
        // Verwaltungsseite zu springen waere ein Kontextverlust - der
        // Bearbeiter ist mit diesem einen Pferd noch nicht fertig.
        if (($_POST['zurueck'] ?? '') === 'pferd' && $horseId) {
            header('Location: /admin/horses/edit?id=' . $horseId);
            exit;
        }

        header('Location: /plugin/galerie/verwaltung');
        exit;
    }

    public function delete(): void {
        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }

        $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
        if ($id) {
            $db = Database::getInstance();
            $stmt = $db->prepare('SELECT type, file_path FROM `plugin_galerie_media` WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                if ($row['type'] === 'image' && !empty($row['file_path'])) {
                    // file_path ist ein selbst generierter Pfad unter
                    // /uploads/plugin_galerie/ (siehe handleImageUpload) -
                    // basename() verhindert zusätzlich jedes Traversal.
                    $path = dirname(__DIR__, 2) . '/public/uploads/plugin_galerie/' . basename((string) $row['file_path']);
                    if (is_file($path)) {
                        @unlink($path);
                    }
                }
                $deleteStmt = $db->prepare('DELETE FROM `plugin_galerie_media` WHERE id = :id');
                $deleteStmt->execute(['id' => $id]);
            }
        }

        // Aus dem Bearbeitungsformular heraus dorthin zurueck (#88).
        $horseId = (int) ($_POST['horse_id'] ?? 0);
        if (($_POST['zurueck'] ?? '') === 'pferd' && $horseId > 0) {
            header('Location: /admin/horses/edit?id=' . $horseId);
            exit;
        }

        // Zurück auf die Listenseite, von der gelöscht wurde (#74); index()
        // klemmt einen inzwischen zu großen Wert selbst auf die letzte Seite.
        $seite = (int) ($_POST['seite'] ?? 1);
        header('Location: /plugin/galerie/verwaltung' . ($seite > 1 ? '?seite=' . $seite : ''));
        exit;
    }

    /**
     * Serverseitige Pferdesuche für die Datalist (#74, Muster
     * Framework-Katalog): JSON-Liste {id, label} über eine Teilstring-Suche
     * im Namen, höchstens SEARCH_LIMIT Treffer. Läuft über denselben
     * Konstruktor-Schutz (galerie.manage) wie die Verwaltungsseite.
     */
    public function suche(): void {
        header('Content-Type: application/json; charset=utf-8');

        $q = trim((string) ($_GET['q'] ?? ''));
        if ($q === '') {
            echo json_encode([]);
            exit;
        }

        $stmt = Database::getInstance()->prepare(
            'SELECT id, name, birth_year FROM horses
             WHERE deleted_at IS NULL AND name LIKE ?
             ORDER BY name ASC, id ASC LIMIT ' . self::SEARCH_LIMIT
        );
        $stmt->execute(['%' . addcslashes($q, '\\%_') . '%']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Label-Duplikate (gleicher Name und Jahrgang) eindeutig machen: Die
        // Datalist mappt Label -> ID, und der No-JS-Fallback löst das
        // "[#id]"-Suffix in resolveHorseId() wieder auf.
        $labelCounts = [];
        foreach ($rows as $row) {
            $label = self::horseLabel($row);
            $labelCounts[$label] = ($labelCounts[$label] ?? 0) + 1;
        }

        $result = [];
        foreach ($rows as $row) {
            $label = self::horseLabel($row);
            if ($labelCounts[$label] > 1) {
                $label .= ' [#' . (int) $row['id'] . ']';
            }
            $result[] = ['id' => (int) $row['id'], 'label' => $label];
        }

        echo json_encode($result);
        exit;
    }

    /**
     * No-JS-Fallback: löst den getippten Text des Suchfelds serverseitig zu
     * einer Pferde-ID auf - nur bei eindeutigem Treffer, sonst null.
     */
    private function resolveHorseId(PDO $db, string $q): ?int {
        // 1) Eindeutigkeits-Suffix aus der Vorschlagsliste: "… [#123]"
        if (preg_match('/\[#(\d+)\]\s*$/', $q, $m)) {
            $stmt = $db->prepare('SELECT id FROM horses WHERE id = ? AND deleted_at IS NULL');
            $stmt->execute([(int) $m[1]]);
            $id = $stmt->fetchColumn();
            return $id !== false ? (int) $id : null;
        }

        // 2) Label-Form "Name (Jahrgang)"
        if (preg_match('/^(.*\S)\s*\((\d{3,4})\)$/u', $q, $m)) {
            $stmt = $db->prepare('SELECT id FROM horses WHERE deleted_at IS NULL AND name = ? AND birth_year = ? LIMIT 2');
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
        $stmt = $db->prepare('SELECT id FROM horses WHERE deleted_at IS NULL AND name = ? LIMIT 2');
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

    /**
     * Gleiches Upload-/Validierungsmuster wie HorseController::
     * handleImageUpload() im Kern (echte MIME-Prüfung per finfo, max. 5 MB,
     * Zufallsname), nur mit eigenem Zielverzeichnis unter
     * public/uploads/plugin_galerie/.
     */
    private function handleImageUpload(?array $file): ?string {
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) === 0) {
            return null;
        }

        if ($file['size'] > 5 * 1024 * 1024) {
            return null;
        }

        $allowedMimeTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);

        if (!isset($allowedMimeTypes[$mime])) {
            return null;
        }

        $uploadDir = dirname(__DIR__, 2) . '/public/uploads/plugin_galerie/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $filename = 'galerie_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $allowedMimeTypes[$mime];
        if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
            return null;
        }

        return '/uploads/plugin_galerie/' . $filename;
    }
}
