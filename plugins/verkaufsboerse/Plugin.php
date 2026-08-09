<?php
// verkaufsboerse/Plugin.php
//
// Addon für Hengstverzeichnis_Framework: löst
// Celestial0579/Hengstverzeichnis_Addons#13. Markiert Pferde als "zum
// Verkauf"/"zur Vermittlung" - bewusst unabhängig von horses.status (ein
// Pferd bleibt z. B. weiterhin 'active'/gekört und ist zusätzlich gelistet),
// mit eigener öffentlicher Übersichtsseite und einem Kontaktformular je
// Inserat direkt auf der Pferde-Detailseite.
//
// Installation (lokal im Framework-Repo):
//   cp -r verkaufsboerse plugins/verkaufsboerse
// Danach unter Admin -> Plugins verwalten (/admin/plugins) aktivieren und
// der gewünschten Gruppe unter /admin/groups die Berechtigung
// "Verkaufsbörse -> Verwalten" zuweisen.
//
// Siehe docs/plugin-development.md im Framework-Repo für die vollständige
// Hook-/Routen-/Berechtigungs-Referenz.

namespace Plugin\Verkaufsboerse;

use App\Controllers\BaseController;
use App\Database;
use App\Plugin\HookManager;
use App\Router;
use App\Security\ClientIp;
use App\Security\RateLimiter;
use App\Service\Mailer;
use PDO;

class Plugin {

    public function register(HookManager $hooks): void {
        $this->ensureTable();
        $hooks->addFilter('horse.detail_sections', [$this, 'addDetailSection']);
        $hooks->addFilter('admin.dashboard_tiles', [$this, 'addDashboardTile']);
    }

    private function ensureTable(): void {
        Database::getInstance()->exec(
            'CREATE TABLE IF NOT EXISTS `plugin_verkaufsboerse_listings` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `horse_id` INT NOT NULL UNIQUE,
                `price` DECIMAL(10,2) NULL DEFAULT NULL,
                `price_on_request` TINYINT(1) NOT NULL DEFAULT 0,
                `description` TEXT NULL DEFAULT NULL,
                `contact_email` VARCHAR(150) NOT NULL,
                `listed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `listed_until` DATE NULL DEFAULT NULL,
                FOREIGN KEY (`horse_id`) REFERENCES `horses`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /**
     * Filter-Beispiel: zeigt ein "Zum Verkauf"-Badge samt Preis und
     * Kontaktformular an, wenn für dieses Pferd ein aktives Inserat existiert
     * (kein `listed_until` in der Vergangenheit).
     */
    public function addDetailSection(array $sections, array $horse, array $horsePersons, ?array $pedigree): array {
        $horseId = (int) $horse['id'];

        $stmt = Database::getInstance()->prepare(
            'SELECT * FROM `plugin_verkaufsboerse_listings`
             WHERE horse_id = :id AND (listed_until IS NULL OR listed_until >= CURDATE())'
        );
        $stmt->execute(['id' => $horseId]);
        $listing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$listing) {
            return $sections;
        }

        $priceText = !empty($listing['price_on_request']) || $listing['price'] === null
            ? 'Preis auf Anfrage'
            : number_format((float) $listing['price'], 2, ',', '.') . ' €';
        $csrfToken = htmlspecialchars(Router::generateCsrfToken(), ENT_QUOTES, 'UTF-8');

        $html = '<div style="margin-top:1rem;padding:1rem;background:var(--info-soft-bg);border:1px solid var(--warning-fg);border-radius:6px;">';
        $html .= '<h3 style="margin-top:0;">🏷️ Zum Verkauf - ' . htmlspecialchars($priceText, ENT_QUOTES, 'UTF-8') . '</h3>';

        if (!empty($listing['description'])) {
            $html .= '<p>' . nl2br(htmlspecialchars((string) $listing['description'], ENT_QUOTES, 'UTF-8')) . '</p>';
        }

        if (($_GET['verkaufsanfrage'] ?? '') === 'erfolg') {
            $html .= '<p style="color:var(--success-fg);background:var(--success-soft-bg);padding:0.6rem;border-radius:4px;">Ihre Anfrage wurde erfolgreich versendet.</p>';
        } elseif (($_GET['verkaufsanfrage'] ?? '') === 'fehler') {
            $html .= '<p style="color:var(--danger-fg);background:var(--danger-soft-bg);padding:0.6rem;border-radius:4px;">Ihre Anfrage konnte nicht versendet werden. Bitte versuchen Sie es später erneut.</p>';
        }

        $html .= '<form method="POST" action="/plugin/verkaufsboerse/kontakt">';
        $html .= '<input type="hidden" name="csrf_token" value="' . $csrfToken . '">';
        $html .= '<input type="hidden" name="horse_id" value="' . $horseId . '">';
        $html .= '<div style="position:absolute;left:-9999px;" aria-hidden="true">'
            . '<label for="verkaufsboerse-webseite">Webseite (bitte leer lassen)</label>'
            . '<input type="text" id="verkaufsboerse-webseite" name="webseite" tabindex="-1" autocomplete="off">'
            . '</div>';
        $html .= '<label style="display:block;margin-top:0.5rem;font-size:0.9em;">Ihr Name<br>'
            . '<input type="text" name="requester_name" required style="width:100%;padding:0.4rem;margin-top:0.2rem;"></label>';
        $html .= '<label style="display:block;margin-top:0.5rem;font-size:0.9em;">Ihre E-Mail-Adresse<br>'
            . '<input type="email" name="requester_email" required style="width:100%;padding:0.4rem;margin-top:0.2rem;"></label>';
        $html .= '<label style="display:block;margin-top:0.5rem;font-size:0.9em;">Nachricht<br>'
            . '<textarea name="message" required rows="3" style="width:100%;padding:0.4rem;margin-top:0.2rem;"></textarea></label>';
        $html .= '<button type="submit" style="margin-top:0.5rem;padding:0.6rem 1.2rem;">Kontakt aufnehmen</button>';
        $html .= '</form></div>';

        $sections[] = $html;
        return $sections;
    }

    public function addDashboardTile(array $tiles): array {
        $tiles[] = [
            'url' => '/plugin/verkaufsboerse/verwaltung',
            'label' => 'Verkaufsbörse',
            'icon' => '🏷️',
        ];
        return $tiles;
    }

    /**
     * @return array<int, array{module:string, action:string, label:string, module_label:string}>
     */
    public function permissions(): array {
        return [
            [
                'module' => 'verkaufsboerse',
                'action' => 'manage',
                'label' => 'Verwalten',
                'module_label' => 'Verkaufsbörse',
            ],
        ];
    }

    /**
     * @return array<int, array{method:string, path:string, callback:array}>
     */
    public function routes(): array {
        return [
            ['method' => 'GET', 'path' => '/liste', 'callback' => [ListeController::class, 'show']],
            ['method' => 'GET', 'path' => '/verwaltung', 'callback' => [VerwaltungController::class, 'index']],
            ['method' => 'POST', 'path' => '/verwaltung/store', 'callback' => [VerwaltungController::class, 'store']],
            ['method' => 'POST', 'path' => '/verwaltung/delete', 'callback' => [VerwaltungController::class, 'delete']],
            ['method' => 'POST', 'path' => '/kontakt', 'callback' => [KontaktController::class, 'submit']],
        ];
    }
}

/**
 * Öffentliche Übersicht aller aktiven Inserate. Verlinkt für Details/Kontakt
 * auf die jeweilige Pferde-Detailseite (dort zeigt horse.detail_sections
 * bereits Preis, Beschreibung und Kontaktformular) - keine doppelte
 * Kontaktformular-Logik auf zwei Seiten.
 */
class ListeController extends BaseController {

    public function show(): void {
        // Öffentliche Sichtbarkeit exakt wie im Kern (PublicController::catalog):
        // Liste nur, wenn die Gast-Gruppe Pferde sehen darf.
        if (!$this->hasPermission('horses', 'view')) {
            $this->renderNotFound('Nicht gefunden.');
        }

        // Öffentliche Sichtbarkeitsregel (#51) - Referenz für alle drei
        // Call-Sites dieses Plugins: sichtbar ist ein Inserat nur, wenn das
        // Pferd weder im Papierkorb (deleted_at) noch unveröffentlicht ist UND
        // das Inserat nicht abgelaufen ist. Die Verwaltung listet dagegen
        // bewusst ALLE Inserate mit ausgewiesenem Status; das Kontaktformular
        // (KontaktController::submit) prüft exakt diese Regel erneut.
        $listings = Database::getInstance()->query(
            'SELECT l.*, h.name AS horse_name, h.birth_year, h.color, h.image_url
             FROM `plugin_verkaufsboerse_listings` l
             JOIN horses h ON h.id = l.horse_id AND h.deleted_at IS NULL AND h.is_published = 1
             WHERE l.listed_until IS NULL OR l.listed_until >= CURDATE()
             ORDER BY l.listed_at DESC'
        )->fetchAll(PDO::FETCH_ASSOC);

        echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><title>Verkaufsbörse</title>';
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
            body{font-family:sans-serif;padding:2rem;max-width:900px;margin:0 auto;background:var(--bg-color);}
            .listing{display:flex;gap:1rem;padding:1rem;border-bottom:1px solid var(--border-color);align-items:center;}
            .listing img{width:100px;height:100px;object-fit:cover;border-radius:6px;}
            .listing h2{margin:0 0 0.3rem 0;font-size:1.1rem;}
            .price{font-weight:bold;color:var(--warning-fg);}
        </style></head><body>';
        echo '<h1>🏷️ Verkaufs-/Vermittlungsbörse</h1>';

        foreach ($listings as $listing) {
            $priceText = !empty($listing['price_on_request']) || $listing['price'] === null
                ? 'Preis auf Anfrage'
                : number_format((float) $listing['price'], 2, ',', '.') . ' €';

            echo '<div class="listing">';
            if (!empty($listing['image_url'])) {
                echo '<img src="' . htmlspecialchars((string) $listing['image_url'], ENT_QUOTES, 'UTF-8') . '" alt="">';
            }
            echo '<div>';
            echo '<h2><a href="/hengst?id=' . (int) $listing['horse_id'] . '">' . htmlspecialchars((string) $listing['horse_name'], ENT_QUOTES, 'UTF-8') . '</a></h2>';
            echo '<div class="price">' . htmlspecialchars($priceText, ENT_QUOTES, 'UTF-8') . '</div>';
            if (!empty($listing['birth_year'])) {
                echo '<div>Geburtsjahr: ' . htmlspecialchars((string) $listing['birth_year'], ENT_QUOTES, 'UTF-8') . '</div>';
            }
            echo '</div></div>';
        }

        if (empty($listings)) {
            echo '<p>Aktuell keine Pferde in der Verkaufsbörse gelistet.</p>';
        }

        echo '</body></html>';
    }
}

/**
 * Admin-Verwaltung der Inserate. Zugriffsschutz über die selbst
 * registrierte Berechtigung "verkaufsboerse.manage". `horse_id` ist in der
 * Tabelle UNIQUE - store() ist daher ein Upsert (ein Pferd hat höchstens ein
 * aktives Inserat, erneutes Speichern aktualisiert es statt einen Fehler
 * durch den doppelten Schlüssel auszulösen).
 */
class VerwaltungController extends BaseController {

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
        $this->requirePermission('verkaufsboerse', 'manage');
    }

    public function index(): void {
        $db = Database::getInstance();

        $horses = $db->query(
            'SELECT id, name, birth_year FROM horses WHERE deleted_at IS NULL ORDER BY name ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        // Bewusst OHNE Sichtbarkeitsfilter (anders als die öffentliche Börse,
        // siehe ListeController::show()): Die Verwaltung soll ALLE Inserate
        // zeigen - aber mit ausgewiesenem Status (#51), damit die Diskrepanz
        // "Verwaltung listet es, Börse zeigt es nicht" sichtbar wird, statt
        // still nebeneinander zu existieren (Pferd im Papierkorb/unveröffentlicht,
        // Inserat abgelaufen).
        $listings = $db->query(
            'SELECT l.*, h.name AS horse_name, h.deleted_at AS horse_deleted_at, h.is_published AS horse_is_published
             FROM `plugin_verkaufsboerse_listings` l
             JOIN horses h ON h.id = l.horse_id
             ORDER BY l.listed_at DESC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $csrfToken = Router::generateCsrfToken();

        echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><title>Verkaufsbörse verwalten</title>';
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
            body{font-family:sans-serif;padding:2rem;max-width:900px;margin:0 auto;background:var(--bg-color);}
            table{width:100%;border-collapse:collapse;margin-top:1.5rem;}
            th,td{text-align:left;padding:0.5rem;border-bottom:1px solid var(--border-color);font-size:0.9rem;}
            label{display:block;margin-top:0.8rem;font-weight:bold;font-size:0.9rem;}
            input,select,textarea{width:100%;padding:0.4rem;margin-top:0.2rem;}
            .row{display:grid;grid-template-columns:1fr 1fr;gap:1rem;}
        </style></head><body>';
        echo '<h1>🏷️ Verkaufsbörse verwalten</h1>';

        echo '<h2>Inserat anlegen/aktualisieren</h2>';
        echo '<form method="POST" action="/plugin/verkaufsboerse/verwaltung/store">';
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
        echo '<div><label for="price">Preis (€, leer = auf Anfrage)</label><input type="number" step="0.01" name="price" id="price"></div>';
        echo '<div><label for="contact_email">Kontakt-E-Mail</label><input type="email" name="contact_email" id="contact_email" required></div>';
        echo '</div>';

        echo '<label for="description">Beschreibung</label><textarea name="description" id="description" rows="3"></textarea>';
        echo '<label for="listed_until">Gelistet bis (optional)</label><input type="date" name="listed_until" id="listed_until">';

        echo '<p><button type="submit" style="margin-top:1.2rem;padding:0.6rem 1.2rem;">Speichern</button></p>';
        echo '</form>';

        echo '<h2>Aktuelle Inserate</h2>';
        echo '<table><thead><tr><th>Pferd</th><th>Sichtbarkeit</th><th>Preis</th><th>Kontakt</th><th>Gelistet bis</th><th></th></tr></thead><tbody>';
        foreach ($listings as $row) {
            $priceText = !empty($row['price_on_request']) || $row['price'] === null
                ? 'auf Anfrage'
                : number_format((float) $row['price'], 2, ',', '.') . ' €';

            // Warum die öffentliche Börse dieses Inserat ggf. NICHT zeigt (#51) -
            // dieselben Bedingungen wie im JOIN von ListeController::show().
            if ($row['horse_deleted_at'] !== null) {
                $visibility = '<span style="color:var(--danger-fg);font-weight:bold;">im Papierkorb - öffentlich unsichtbar</span>';
            } elseif (empty($row['horse_is_published'])) {
                $visibility = '<span style="color:var(--warning-fg);font-weight:bold;">Pferd unveröffentlicht - öffentlich unsichtbar</span>';
            } elseif (!empty($row['listed_until']) && $row['listed_until'] < date('Y-m-d')) {
                $visibility = '<span style="color:var(--warning-fg);font-weight:bold;">abgelaufen - öffentlich unsichtbar</span>';
            } else {
                $visibility = '<span style="color:var(--success-fg);">öffentlich sichtbar</span>';
            }

            echo '<tr>';
            echo '<td>' . htmlspecialchars((string) $row['horse_name'], ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . $visibility . '</td>';
            echo '<td>' . htmlspecialchars($priceText, ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars((string) $row['contact_email'], ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars((string) ($row['listed_until'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td><form method="POST" action="/plugin/verkaufsboerse/verwaltung/delete" style="margin:0;" onsubmit="return confirm(\'Inserat wirklich entfernen?\');">'
                . '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') . '">'
                . '<input type="hidden" name="id" value="' . (int) $row['id'] . '">'
                . '<button type="submit" style="color:var(--danger-fg);">Entfernen</button></form></td>';
            echo '</tr>';
        }
        if (empty($listings)) {
            echo '<tr><td colspan="6">Noch keine Inserate erfasst.</td></tr>';
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
        $contactEmail = trim($_POST['contact_email'] ?? '');

        if ($horseId && filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
            $price = ($_POST['price'] ?? '') !== '' ? (float) $_POST['price'] : null;

            $stmt = Database::getInstance()->prepare(
                'INSERT INTO `plugin_verkaufsboerse_listings`
                    (horse_id, price, price_on_request, description, contact_email, listed_until)
                 VALUES (:horse_id, :price, :price_on_request, :description, :contact_email, :listed_until)
                 ON DUPLICATE KEY UPDATE
                    price = VALUES(price),
                    price_on_request = VALUES(price_on_request),
                    description = VALUES(description),
                    contact_email = VALUES(contact_email),
                    listed_until = VALUES(listed_until)'
            );
            $stmt->execute([
                'horse_id' => $horseId,
                'price' => $price,
                'price_on_request' => $price === null ? 1 : 0,
                'description' => trim($_POST['description'] ?? '') ?: null,
                'contact_email' => $contactEmail,
                'listed_until' => !empty($_POST['listed_until']) ? $_POST['listed_until'] : null,
            ]);
        }

        header('Location: /plugin/verkaufsboerse/verwaltung');
        exit;
    }

    public function delete(): void {
        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }

        $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
        if ($id) {
            $stmt = Database::getInstance()->prepare('DELETE FROM `plugin_verkaufsboerse_listings` WHERE id = :id');
            $stmt->execute(['id' => $id]);
        }

        header('Location: /plugin/verkaufsboerse/verwaltung');
        exit;
    }
}

/**
 * Verarbeitet die Kontaktanfrage aus dem Formular auf der Pferde-
 * Detailseite. Bewusst ohne Zugriffsschutz (öffentliche Route für anonyme
 * Interessenten), mit Honeypot- und IP-Rate-Limiting wie beim
 * deckanfrage-Addon (eigener RateLimiter-`type`, um Kollisionen mit anderen
 * Formularen zu vermeiden).
 */
class KontaktController extends BaseController {

    public function submit(): void {
        $horseId = !empty($_POST['horse_id']) ? (int) $_POST['horse_id'] : null;

        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }

        if (!empty($_POST['webseite'])) {
            $this->redirectBack($horseId, 'erfolg');
        }

        $ip = ClientIp::resolve();
        if (RateLimiter::tooManyAttempts($ip, 'verkaufsboerse', 5, 3600)) {
            $this->redirectBack($horseId, 'fehler');
        }
        RateLimiter::recordAttempt($ip, 'verkaufsboerse');

        $requesterName = trim($_POST['requester_name'] ?? '');
        $requesterEmail = trim($_POST['requester_email'] ?? '');
        $message = trim($_POST['message'] ?? '');

        if (!$horseId || $requesterName === '' || $message === '' || !filter_var($requesterEmail, FILTER_VALIDATE_EMAIL)) {
            $this->redirectBack($horseId, 'fehler');
        }

        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT l.contact_email, h.name AS horse_name
             FROM `plugin_verkaufsboerse_listings` l
             JOIN horses h ON h.id = l.horse_id AND h.deleted_at IS NULL AND h.is_published = 1
             WHERE l.horse_id = ?
               AND (l.listed_until IS NULL OR l.listed_until >= CURDATE())'
        );
        $stmt->execute([$horseId]);
        $listing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$listing) {
            $this->redirectBack($horseId, 'fehler');
        }

        $siteName = htmlspecialchars((string) ($this->settings['site_name'] ?? 'Hengstverzeichnis'), ENT_QUOTES, 'UTF-8');
        $horseName = htmlspecialchars((string) $listing['horse_name'], ENT_QUOTES, 'UTF-8');
        $safeName = htmlspecialchars($requesterName, ENT_QUOTES, 'UTF-8');
        $safeEmail = htmlspecialchars($requesterEmail, ENT_QUOTES, 'UTF-8');
        $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));

        $body = "<p>Neue Anfrage aus der Verkaufsbörse von {$siteName} für <strong>{$horseName}</strong>.</p>"
            . "<p><strong>Von:</strong> {$safeName}<br>"
            . "<strong>Antwort direkt an:</strong> {$safeEmail}</p>"
            . "<p><strong>Nachricht:</strong><br>{$safeMessage}</p>";

        $sent = (new Mailer())->send(
            (string) $listing['contact_email'],
            "Verkaufsanfrage für {$listing['horse_name']}",
            $body
        );

        $this->redirectBack($horseId, $sent ? 'erfolg' : 'fehler');
    }

    private function redirectBack(?int $horseId, string $status): void {
        header('Location: /hengst?id=' . (int) $horseId . '&verkaufsanfrage=' . $status);
        exit;
    }
}
