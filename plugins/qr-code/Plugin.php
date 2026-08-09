<?php
// qr-code/Plugin.php
//
// Addon für Hengstverzeichnis_Framework: löst
// Celestial0579/Hengstverzeichnis_Addons#17. Zeigt auf der öffentlichen
// Pferde-Detailseite einen QR-Code, der auf die Profil-URL verlinkt, sowie
// eine druckfertige Aushang-Ansicht (z. B. für den Stall).
//
// Der QR-Code wird komplett clientseitig mit der bereits im Framework-Kern
// vendorten Bibliothek public/js/qrcode.js gerendert (dieselbe, die für die
// 2FA-Einrichtung genutzt wird, siehe src/Views/2fa_setup.php) - keine neue
// Abhängigkeit, kein externer QR-Code-Dienst.
//
// Installation (lokal im Framework-Repo):
//   cp -r qr-code plugins/qr-code
// Danach unter Admin -> Plugins verwalten (/admin/plugins) aktivieren.
//
// Siehe docs/plugin-development.md im Framework-Repo für die vollständige
// Hook-/Routen-/Berechtigungs-Referenz.

namespace Plugin\QrCode;

use App\Controllers\BaseController;
use App\Database;
use App\Plugin\HookManager;

class Plugin {

    public function register(HookManager $hooks): void {
        $hooks->addFilter('horse.detail_sections', [$this, 'addDetailSection']);
    }

    /**
     * Filter-Beispiel: hängt einen aufklappbaren QR-Code-Bereich sowie einen
     * Link zur druckfertigen Aushang-Ansicht an die öffentliche Detailseite
     * an. Der QR-Code kodiert die eigene Profil-URL (window.location.origin +
     * Pfad) - läuft daher unverändert auf jeder Domain, ohne dass der Plugin-
     * Code selbst eine Basis-URL kennen müsste.
     */
    public function addDetailSection(array $sections, array $horse, array $horsePersons, ?array $pedigree): array {
        $horseId = (int) $horse['id'];
        $detailPath = '/horse?id=' . $horseId;
        $detailPathJson = json_encode($detailPath, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $canvasId = 'qr-code-plugin-canvas-' . $horseId;

        $html = '<div style="margin-top:0.5rem;">';
        $html .= '<button type="button" onclick="document.getElementById(\'' . $canvasId . '\').style.display = '
            . '(document.getElementById(\'' . $canvasId . '\').style.display === \'none\' ? \'inline-block\' : \'none\')" '
            . 'style="padding:0.5rem 1rem;background:var(--surface-muted);border:1px solid var(--border-color);border-radius:6px;cursor:pointer;">📱 QR-Code anzeigen</button> ';
        $html .= '<a href="/plugin/qr-code/aushang?id=' . $horseId . '" target="_blank" rel="noopener" '
            . 'style="padding:0.5rem 1rem;background:var(--surface-muted);border:1px solid var(--border-color);border-radius:6px;text-decoration:none;color:inherit;display:inline-block;">🖨️ Aushang drucken</a>';
        $html .= '<div id="' . $canvasId . '" style="display:none;margin-top:0.7rem;"></div>';
        $html .= '<script src="/js/qrcode.js"></script>';
        $html .= '<script>
            new QRCode(document.getElementById(' . json_encode($canvasId) . '), {
                text: window.location.origin + ' . $detailPathJson . ',
                width: 180,
                height: 180,
                correctLevel: QRCode.CorrectLevel.M
            });
        </script>';
        $html .= '</div>';

        $sections[] = $html;
        return $sections;
    }

    /**
     * @return array<int, array{method:string, path:string, callback:array}>
     */
    public function routes(): array {
        return [
            [
                'method' => 'GET',
                'path' => '/aushang',
                'callback' => [AushangController::class, 'show'],
            ],
        ];
    }
}

/**
 * Druckfertige Aushang-Ansicht (großer QR-Code + Name + Foto), z. B. zum
 * Ausdrucken und Aufhängen am Stall. Bewusst ohne Zugriffsschutz - zeigt
 * exakt dieselben, bereits öffentlich über /horse?id=... einsehbaren Daten
 * in anderer Aufbereitung, analog zum pedigree-export-Addon.
 */
class AushangController extends BaseController {

    public function show(): void {
        // Öffentliche Sichtbarkeit exakt wie im Kern (PublicController::horseDetail):
        // Aushang nur, wenn die Gast-Gruppe Pferde sehen darf.
        if (!$this->hasPermission('horses', 'view')) {
            $this->renderNotFound('Für dieses Pferd konnte kein Aushang erstellt werden.');
        }

        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if (!$id) {
            $this->renderNotFound('Kein Pferd angegeben.');
        }

        // Nur veröffentlichte Pferde (is_published = 1) - unveröffentlichte liefern
        // wie ein fehlender Datensatz eine 404, damit der Aushang keine im Kern
        // verborgenen Daten preisgibt.
        $stmt = Database::getInstance()->prepare(
            'SELECT id, name, birth_year, image_url FROM horses WHERE id = ? AND deleted_at IS NULL AND is_published = 1'
        );
        $stmt->execute([$id]);
        $horse = $stmt->fetch();

        if (!$horse) {
            $this->renderNotFound('Für dieses Pferd konnte kein Aushang erstellt werden.');
        }

        $name = htmlspecialchars((string) $horse['name'], ENT_QUOTES, 'UTF-8');
        $year = !empty($horse['birth_year']) ? htmlspecialchars((string) $horse['birth_year'], ENT_QUOTES, 'UTF-8') : '';
        $imageUrl = !empty($horse['image_url']) ? htmlspecialchars((string) $horse['image_url'], ENT_QUOTES, 'UTF-8') : null;
        $detailPathJson = json_encode('/horse?id=' . (int) $horse['id'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><title>Aushang ' . $name . '</title>';
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
        echo '<style>
            body { font-family: sans-serif; text-align: center; padding: 2rem; background: var(--bg-color); }
            .toolbar { margin-bottom: 2rem; }
            .toolbar button { padding: 0.6rem 1.2rem; font-size: 1rem; cursor: pointer; }
            img.photo { max-width: 300px; max-height: 300px; border-radius: 8px; margin-bottom: 1rem; }
            h1 { margin: 0.5rem 0; }
            #qrcode-canvas { display: inline-block; margin: 1.5rem 0; }
            @media print {
                .no-print { display: none !important; }
                /* Druck-Aushang bleibt bewusst hell, auch wenn data-theme=dark aktiv ist. */
                body { background: #fff; color: #222; }
            }
        </style>';
        echo '</head><body>';

        echo '<div class="toolbar no-print"><button type="button" onclick="window.print()">🖨️ Drucken</button></div>';

        if ($imageUrl) {
            echo '<img class="photo" src="' . $imageUrl . '" alt="' . $name . '">';
        }
        echo '<h1>' . $name . '</h1>';
        if ($year !== '') {
            echo '<p>Geboren ' . $year . '</p>';
        }

        echo '<div id="qrcode-canvas"></div>';
        echo '<p>Scannen Sie den QR-Code für weitere Informationen.</p>';

        echo '<script src="/js/qrcode.js"></script>';
        echo '<script>
            new QRCode(document.getElementById("qrcode-canvas"), {
                text: window.location.origin + ' . $detailPathJson . ',
                width: 220,
                height: 220,
                correctLevel: QRCode.CorrectLevel.M
            });
        </script>';

        echo '</body></html>';
    }
}
