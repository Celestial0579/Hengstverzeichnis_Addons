<?php
// deckanfrage/Plugin.php
//
// Addon für Hengstverzeichnis_Framework: löst
// Celestial0579/Hengstverzeichnis_Addons#12. Ergänzt ein "Deckanfrage
// stellen"-Formular auf der öffentlichen Pferde-Detailseite, sichtbar wenn
// eine Deckstation mit hinterlegter E-Mail-Adresse verknüpft ist. Die
// Anfrage wird über den bereits vorhandenen App\Service\Mailer direkt an
// die Deckstation gesendet und zusätzlich protokolliert.
//
// Installation (lokal im Framework-Repo):
//   cp -r deckanfrage plugins/deckanfrage
// Danach unter Admin -> Plugins verwalten (/admin/plugins) aktivieren.
//
// Siehe docs/plugin-development.md im Framework-Repo für die vollständige
// Hook-/Routen-/Berechtigungs-Referenz.

namespace Plugin\Deckanfrage;

use App\Controllers\BaseController;
use App\Database;
use App\Plugin\HookManager;
use App\Router;
use App\Security\ClientIp;
use App\Security\RateLimiter;
use App\Service\Mailer;

class Plugin {

    public function register(HookManager $hooks): void {
        $this->ensureTable();
        $hooks->addFilter('horse.detail_sections', [$this, 'addDetailSection']);
    }

    private function ensureTable(): void {
        Database::getInstance()->exec(
            'CREATE TABLE IF NOT EXISTS `plugin_deckanfrage_requests` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `horse_id` INT NOT NULL,
                `requester_name` VARCHAR(150) NOT NULL,
                `requester_email` VARCHAR(150) NOT NULL,
                `message` TEXT NOT NULL,
                `sent` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`horse_id`) REFERENCES `horses`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /**
     * Filter-Beispiel: zeigt das Formular nur, wenn dem Pferd über die
     * Deckstation eine E-Mail-Adresse hinterlegt ist - `$horse` enthält das
     * bereits (PublicController::horseDetail() joint `breeding_stations` als
     * `station_email`/`station_name`), keine eigene Datenbankabfrage nötig.
     *
     * Wichtig: Der Kern übergibt dem Hook ÖFFENTLICH GEFILTERTE Daten. Alle
     * `station_*`-Felder sind gemeinsam null, wenn die Station unveröffentlicht
     * oder gelöscht ist oder der Gast-Gruppe `breeding_stations.view` fehlt -
     * `$horse['breeding_station_id']` bleibt dabei gesetzt und taugt deshalb
     * NICHT als Bedingung. Genau darum wird hier `station_email` geprüft und
     * nicht die Verknüpfung. Siehe docs/plugin-development.md des Kerns,
     * Abschnitt "Was in $horse und $horsePersons steht".
     */
    public function addDetailSection(array $sections, array $horse, array $horsePersons, ?array $pedigree): array {
        if (empty($horse['station_email'])) {
            return $sections;
        }

        $horseId = (int) $horse['id'];
        $csrfToken = htmlspecialchars(Router::generateCsrfToken(), ENT_QUOTES, 'UTF-8');

        $html = '<div style="margin-top:1rem;padding:1rem;background:#f8f9fa;border-radius:6px;">';
        $html .= '<h3 style="margin-top:0;">📨 Deckanfrage stellen</h3>';

        if (($_GET['deckanfrage'] ?? '') === 'erfolg') {
            $html .= '<p style="color:#155724;background:#d4edda;padding:0.6rem;border-radius:4px;">Ihre Anfrage wurde erfolgreich versendet.</p>';
        } elseif (($_GET['deckanfrage'] ?? '') === 'fehler') {
            $html .= '<p style="color:#721c24;background:#f8d7da;padding:0.6rem;border-radius:4px;">Ihre Anfrage konnte nicht versendet werden. Bitte versuchen Sie es später erneut.</p>';
        }

        $html .= '<form method="POST" action="/plugin/deckanfrage/anfrage">';
        $html .= '<input type="hidden" name="csrf_token" value="' . $csrfToken . '">';
        $html .= '<input type="hidden" name="horse_id" value="' . $horseId . '">';
        // Honeypot: für Menschen unsichtbares Feld - füllt ein Bot es aus, wird die Anfrage
        // im Controller stillschweigend verworfen, ohne dem Bot einen Hinweis darauf zu geben.
        $html .= '<div style="position:absolute;left:-9999px;" aria-hidden="true">'
            . '<label for="deckanfrage-webseite">Webseite (bitte leer lassen)</label>'
            . '<input type="text" id="deckanfrage-webseite" name="webseite" tabindex="-1" autocomplete="off">'
            . '</div>';

        $html .= '<label style="display:block;margin-top:0.5rem;font-size:0.9em;">Ihr Name<br>'
            . '<input type="text" name="requester_name" required style="width:100%;padding:0.4rem;margin-top:0.2rem;"></label>';
        $html .= '<label style="display:block;margin-top:0.5rem;font-size:0.9em;">Ihre E-Mail-Adresse<br>'
            . '<input type="email" name="requester_email" required style="width:100%;padding:0.4rem;margin-top:0.2rem;"></label>';
        $html .= '<label style="display:block;margin-top:0.5rem;font-size:0.9em;">Nachricht<br>'
            . '<textarea name="message" required rows="4" style="width:100%;padding:0.4rem;margin-top:0.2rem;"></textarea></label>';
        $html .= '<p style="font-size:0.8em;color:#666;margin-top:0.4rem;">Ihre Angaben werden zur Bearbeitung der Deckanfrage an die Deckstation weitergeleitet.</p>';
        $html .= '<button type="submit" style="margin-top:0.5rem;padding:0.6rem 1.2rem;">Anfrage senden</button>';
        $html .= '</form></div>';

        $sections[] = $html;
        return $sections;
    }

    /**
     * @return array<int, array{method:string, path:string, callback:array}>
     */
    public function routes(): array {
        return [
            [
                'method' => 'POST',
                'path' => '/anfrage',
                'callback' => [AnfrageController::class, 'submit'],
            ],
        ];
    }
}

/**
 * Verarbeitet die Formular-Einreichung. Bewusst ohne checkAuth()/
 * requirePermission() - die Route ist für anonyme Besucher gedacht, analog
 * zum bestehenden allgemeinen DSGVO-Kontaktformular (PublicController::
 * dsgvoSubmit()). Eigener CSRF-Schutz über denselben Mechanismus wie
 * Kern-Formulare, Honeypot- und IP-basiertes Rate-Limiting gegen Spam.
 */
class AnfrageController extends BaseController {

    public function submit(): void {
        $horseId = !empty($_POST['horse_id']) ? (int) $_POST['horse_id'] : null;

        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }

        // Honeypot ausgefüllt: stillschweigend verwerfen, als Erfolg ausgeben.
        if (!empty($_POST['webseite'])) {
            $this->redirectBack($horseId, 'erfolg');
        }

        $ip = ClientIp::resolve();
        if (RateLimiter::tooManyAttempts($ip, 'deckanfrage', 5, 3600)) {
            $this->redirectBack($horseId, 'fehler');
        }
        RateLimiter::recordAttempt($ip, 'deckanfrage');

        $requesterName = trim($_POST['requester_name'] ?? '');
        $requesterEmail = trim($_POST['requester_email'] ?? '');
        $message = trim($_POST['message'] ?? '');

        if (!$horseId || $requesterName === '' || $message === '' || !filter_var($requesterEmail, FILTER_VALIDATE_EMAIL)) {
            $this->redirectBack($horseId, 'fehler');
        }

        $db = Database::getInstance();
        // bs.is_published = 1 spiegelt exakt den JOIN der oeffentlichen
        // Detailseite (Kern-#122): Das Formular erscheint nur bei
        // veroeffentlichter Station - ohne denselben Filter hier liesse sich
        // per direktem POST (gueltiger CSRF-Token von jeder Seite) weiterhin
        // an eine bewusst unveroeffentlichte Station versenden. Anzeige und
        // Verarbeitung muessen dieselbe Sichtbarkeitsregel anwenden.
        $stmt = $db->prepare(
            'SELECT h.id, h.name, bs.email AS station_email, bs.name AS station_name
             FROM horses h
             LEFT JOIN breeding_stations bs ON h.breeding_station_id = bs.id AND bs.deleted_at IS NULL AND bs.is_published = 1
             WHERE h.id = ? AND h.deleted_at IS NULL AND h.is_published = 1'
        );
        $stmt->execute([$horseId]);
        $horse = $stmt->fetch();

        // Nicht auffindbares/unveröffentlichtes Pferd: stillschweigend verwerfen
        // und wie beim Honeypot "erfolg" melden - der Redirect-Status darf kein
        // Existenz-Orakel für im Kern verborgene Pferde-IDs sein.
        if (!$horse || empty($horse['station_email'])) {
            $this->redirectBack($horseId, 'erfolg');
        }

        $insertStmt = $db->prepare(
            'INSERT INTO `plugin_deckanfrage_requests` (horse_id, requester_name, requester_email, message)
             VALUES (:horse_id, :requester_name, :requester_email, :message)'
        );
        $insertStmt->execute([
            'horse_id' => $horseId,
            'requester_name' => $requesterName,
            'requester_email' => $requesterEmail,
            'message' => $message,
        ]);
        $requestId = (int) $db->lastInsertId();

        $siteName = htmlspecialchars((string) ($this->settings['site_name'] ?? 'Hengstverzeichnis'), ENT_QUOTES, 'UTF-8');
        $horseName = htmlspecialchars((string) $horse['name'], ENT_QUOTES, 'UTF-8');
        $safeName = htmlspecialchars($requesterName, ENT_QUOTES, 'UTF-8');
        $safeEmail = htmlspecialchars($requesterEmail, ENT_QUOTES, 'UTF-8');
        $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));

        $body = "<p>Neue Deckanfrage über {$siteName} für <strong>{$horseName}</strong>.</p>"
            . "<p><strong>Von:</strong> {$safeName}<br>"
            . "<strong>Antwort direkt an:</strong> {$safeEmail}</p>"
            . "<p><strong>Nachricht:</strong><br>{$safeMessage}</p>";

        $sent = (new Mailer())->send(
            (string) $horse['station_email'],
            "Deckanfrage für {$horse['name']}",
            $body
        );

        if ($sent) {
            $updateStmt = $db->prepare('UPDATE `plugin_deckanfrage_requests` SET `sent` = 1 WHERE id = :id');
            $updateStmt->execute(['id' => $requestId]);
        }

        $this->redirectBack($horseId, $sent ? 'erfolg' : 'fehler');
    }

    private function redirectBack(?int $horseId, string $status): void {
        header('Location: /hengst?id=' . (int) $horseId . '&deckanfrage=' . $status);
        exit;
    }
}
