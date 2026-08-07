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
use App\Router;
use PDO;

class Plugin {

    public function register(HookManager $hooks): void {
        $this->ensureTable();
        $hooks->addFilter('horse.detail_sections', [$this, 'addDetailSection']);
        $hooks->addFilter('admin.dashboard_tiles', [$this, 'addDashboardTile']);
    }

    private function ensureTable(): void {
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
                    . 'style="width:100%;height:120px;object-fit:cover;border-radius:6px;cursor:zoom-in;" '
                    . 'onclick="hvGalerieLightbox(this.src, this.alt)">'
                    . ($caption !== '' ? '<figcaption style="font-size:0.8em;color:#666;">' . $caption . '</figcaption>' : '')
                    . '</figure>';
            } elseif ($item['type'] === 'video' && !empty($item['video_url'])) {
                $url = htmlspecialchars((string) $item['video_url'], ENT_QUOTES, 'UTF-8');
                $html .= '<a href="' . $url . '" target="_blank" rel="noopener noreferrer" '
                    . 'style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:120px;'
                    . 'background:#222;color:#fff;border-radius:6px;text-decoration:none;text-align:center;padding:0.4rem;">'
                    . '<span style="font-size:1.8rem;">▶</span>'
                    . '<span style="font-size:0.8em;">' . ($caption !== '' ? $caption : 'Video ansehen') . '</span>'
                    . '</a>';
            }
        }

        $html .= '</div>';

        // Schlanke Lightbox: Overlay-DIV, Schließen per Klick/Escape.
        $html .= '<div id="hv-galerie-lightbox" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.85);'
            . 'z-index:1000;align-items:center;justify-content:center;cursor:zoom-out;" onclick="this.style.display=\'none\'">'
            . '<img id="hv-galerie-lightbox-img" src="" alt="" style="max-width:92vw;max-height:92vh;border-radius:6px;">'
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

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
        $this->requirePermission('galerie', 'manage');
    }

    public function index(): void {
        $db = Database::getInstance();

        $horses = $db->query(
            'SELECT id, name, birth_year FROM horses WHERE deleted_at IS NULL ORDER BY name ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $media = $db->query(
            'SELECT m.*, h.name AS horse_name
             FROM `plugin_galerie_media` m
             JOIN horses h ON h.id = m.horse_id
             ORDER BY h.name ASC, m.sort_order ASC, m.id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $csrfToken = Router::generateCsrfToken();

        echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><title>Galerie verwalten</title>';
        echo '<style>
            body{font-family:sans-serif;padding:2rem;max-width:900px;margin:0 auto;}
            table{width:100%;border-collapse:collapse;margin-top:1.5rem;}
            th,td{text-align:left;padding:0.5rem;border-bottom:1px solid #ddd;font-size:0.9rem;vertical-align:middle;}
            label{display:block;margin-top:0.8rem;font-weight:bold;font-size:0.9rem;}
            input,select{width:100%;padding:0.4rem;margin-top:0.2rem;}
            .row{display:grid;grid-template-columns:1fr 1fr;gap:1rem;}
            .hint{color:#666;font-size:0.85em;margin-top:0.3rem;}
            .thumb{width:60px;height:45px;object-fit:cover;border-radius:4px;}
        </style></head><body>';
        echo '<h1>🖼️ Foto-/Video-Galerie verwalten</h1>';

        echo '<h2>Medium hinzufügen</h2>';
        echo '<form method="POST" action="/plugin/galerie/verwaltung/store" enctype="multipart/form-data">';
        echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') . '">';

        echo '<label for="horse_id">Pferd</label><select name="horse_id" id="horse_id" required>';
        echo '<option value="">– auswählen –</option>';
        foreach ($horses as $h) {
            echo '<option value="' . (int) $h['id'] . '">'
                . htmlspecialchars($h['name'] . ($h['birth_year'] ? ' (' . $h['birth_year'] . ')' : ''), ENT_QUOTES, 'UTF-8')
                . '</option>';
        }
        echo '</select>';

        echo '<div class="row">';
        echo '<div><label for="image">Foto hochladen (JPEG/PNG/WebP, max. 5 MB)</label>'
            . '<input type="file" name="image" id="image" accept="image/jpeg,image/png,image/webp"></div>';
        echo '<div><label for="video_url">ODER Video-Link (YouTube/Vimeo, https)</label>'
            . '<input type="url" name="video_url" id="video_url" placeholder="https://www.youtube.com/watch?v=..."></div>';
        echo '</div>';
        echo '<p class="hint">Genau eines von beiden angeben. Videos werden bewusst nur als externer Link eingebunden (kein Eigen-Hosting).</p>';

        echo '<div class="row">';
        echo '<div><label for="caption">Bildunterschrift (optional)</label><input type="text" name="caption" id="caption" maxlength="255"></div>';
        echo '<div><label for="sort_order">Sortierung (kleinere Zahl zuerst)</label><input type="number" name="sort_order" id="sort_order" value="0"></div>';
        echo '</div>';

        echo '<p><button type="submit" style="margin-top:1.2rem;padding:0.6rem 1.2rem;">Hinzufügen</button></p>';
        echo '</form>';

        echo '<h2>Erfasste Medien</h2>';
        echo '<table><thead><tr><th>Pferd</th><th>Typ</th><th>Vorschau/Link</th><th>Bildunterschrift</th><th>Sortierung</th><th></th></tr></thead><tbody>';
        foreach ($media as $row) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars((string) $row['horse_name'], ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . ($row['type'] === 'image' ? 'Foto' : 'Video') . '</td>';
            echo '<td>';
            if ($row['type'] === 'image' && !empty($row['file_path'])) {
                echo '<img class="thumb" src="' . htmlspecialchars((string) $row['file_path'], ENT_QUOTES, 'UTF-8') . '" alt="">';
            } elseif (!empty($row['video_url'])) {
                $url = htmlspecialchars((string) $row['video_url'], ENT_QUOTES, 'UTF-8');
                echo '<a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . $url . '</a>';
            }
            echo '</td>';
            echo '<td>' . htmlspecialchars((string) ($row['caption'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . (int) $row['sort_order'] . '</td>';
            echo '<td><form method="POST" action="/plugin/galerie/verwaltung/delete" style="margin:0;" onsubmit="return confirm(\'Medium wirklich entfernen?\');">'
                . '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') . '">'
                . '<input type="hidden" name="id" value="' . (int) $row['id'] . '">'
                . '<button type="submit" style="color:#dc3545;">Entfernen</button></form></td>';
            echo '</tr>';
        }
        if (empty($media)) {
            echo '<tr><td colspan="6">Noch keine Medien erfasst.</td></tr>';
        }
        echo '</tbody></table>';

        echo '<p style="margin-top:2rem;"><a href="/admin">Zurück zum Dashboard</a></p>';
        echo '</body></html>';
    }

    public function store(): void {
        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }

        $horseId = !empty($_POST['horse_id']) ? (int) $_POST['horse_id'] : null;
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

        header('Location: /plugin/galerie/verwaltung');
        exit;
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
