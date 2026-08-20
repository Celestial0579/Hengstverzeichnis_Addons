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
// Seit Framework#336 (Addons#138) ist "Deckstation" keine eigene Tabelle
// mehr: Personen und Deckstationen liegen gemeinsam in `contacts`, und
// `horses.breeding_station_id` zeigt auf einen Kontakt dieser Tabelle. Der
// Empfänger einer Deckanfrage ist damit ein Kontakt in der ROLLE
// Deckstation - siehe AnfrageController::submit(), wo die Sichtbarkeitsregel
// dafür ausbuchstabiert ist.
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
use App\Plugin\PluginAudit;
use App\Router;
use App\Security\Captcha;
use App\Security\CaptchaContext;
use App\Security\ClientIp;
use App\Security\RateLimiter;
use App\Service\Mailer;
use PDO;

class Plugin {

    /**
     * Der eigene Slug. Er ist gleichzeitig Protokoll-Kategorie (#352),
     * Formular-Kontext im Captcha-Katalog (#351) und Typ der Rate-Limit-Zeilen
     * - drei Stellen, an denen bisher dieselbe Zeichenkette einzeln
     * abgeschrieben stand.
     */
    public const SLUG = 'deckanfrage';

    /** Der Formular-Kontext dieses Addons im Captcha-Katalog (Framework#351). */
    public const CAPTCHA_CONTEXT = self::SLUG;

    /** Der `type` der eigenen Zeilen in der Kern-Tabelle `login_attempts`. */
    public const RATE_LIMIT_TYPE = self::SLUG;

    public function register(HookManager $hooks): void {
        // Kein ensureTable() mehr: Die Tabelle legt install() an, das der
        // PluginManager bei Aktivierung und nach jedem Addon-Update genau
        // einmal aufruft (Framework #75). Die frueher hier stehende Probe
        // ("SELECT 1 FROM ... LIMIT 1", sonst install() nachholen) war ein
        // Rueckfall fuer Kerne OHNE diesen Hook - den es laut der
        // core_compatibility-Untergrenze in plugin.json nicht mehr gibt.
        // Geblieben waere nur der Preis: eine zusaetzliche Abfrage pro Plugin
        // und Anfrage, bei sieben Addons also sieben Roundtrips, bevor die
        // erste Zeile der Seite steht.
        $hooks->addFilter('horse.detail_sections', [$this, 'addDetailSection']);
    }

    /**
     * Meldet das Deckanfrage-Formular im Captcha-Katalog an (Framework#351).
     *
     * Bis v0.7 hatte dieses Formular nur Honeypot und IP-Rate-Limit - der
     * vorhandene Captcha-Unterbau des Kerns liess sich nicht nutzen, weil ein
     * Addon seinen Formular-Kontext nirgends anmelden konnte. Ohne den Eintrag
     * hier faende der Betreiber unter /admin/system-settings keine Einstellung
     * fuer dieses Formular, und `Captcha::verify()` fiele mit einem
     * Protokolleintrag "unbekannter Formular-Kontext" auf den eingebauten
     * Schutz zurueck.
     *
     * @return array<string, string>
     */
    public function captchaContexts(): array {
        return [self::CAPTCHA_CONTEXT => 'Deckanfrage an eine Deckstation'];
    }

    /**
     * Die Kern-Einstellungen als Schlüssel/Wert-Feld.
     *
     * `Captcha::renderField()` braucht sie, um den vom Betreiber gewählten
     * Anbieter zu bestimmen. Im Hook gibt es kein `$this->settings` wie im
     * Controller - der Hook hängt nicht an einer BaseController-Instanz -,
     * deshalb dieser schmale Leser. Ein Fehlschlag darf die Pferdeseite nicht
     * mitreißen: Ohne Einstellungen greift der eingebaute Anbieter, also der
     * strengere Fall.
     *
     * @return array<string, string>
     */
    private static function kernEinstellungen(): array {
        try {
            $rows = Database::getInstance()
                ->query('SELECT setting_key, setting_value FROM settings')
                ->fetchAll(PDO::FETCH_KEY_PAIR);
            return is_array($rows) ? $rows : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Framework-Hook (#75): Der PluginManager ruft install() bei der
     * Aktivierung und nach einem Addon-Update genau einmal auf - das
     * DDL-Statement läuft damit nicht mehr in jedem Request.
     */
    public function install(): void {
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
     * Deinstallation (Framework#338): Was sich aufzählen lässt, steht im
     * Register `owns` der plugin.json - die Anfragetabelle. `uninstall()`
     * räumt nur das ab, was in KERN-Tabellen liegt und deshalb nicht
     * deklarierbar ist:
     *
     * - `login_attempts` vom Typ `deckanfrage`: Zeilen mit IP-Adressen, die
     *   das Rate-Limit dieses Formulars erzeugt hat. Ohne das Formular haben
     *   sie keinen Zweck mehr, und eine IP-Adresse ist ein personenbezogenes
     *   Datum - Liegenlassen wäre genau der Zustand, den #338 abschafft.
     * - Die Anbieterwahl der Sicherheitsfrage. Ihr Schlüssel gehört dem Kern
     *   (`captcha_provider_<kontext>`) und beginnt deshalb nicht mit
     *   `plugin_`; das Register nimmt ihn nicht an.
     *
     * Die PROTOKOLLEINTRÄGE bleiben ausdrücklich stehen. Sie enthalten keine
     * Absenderdaten (siehe AnfrageController::submit()) und sind der Nachweis,
     * dass hier einmal Anfragen weitergeleitet wurden - ein Nachweis, den das
     * Deinstallieren des Addons löschen könnte, wäre keiner.
     */
    public function uninstall(): void {
        $db = Database::getInstance();

        $stmt = $db->prepare('DELETE FROM `login_attempts` WHERE `type` = ?');
        $stmt->execute([self::RATE_LIMIT_TYPE]);

        $stmt = $db->prepare('DELETE FROM `settings` WHERE `setting_key` = ?');
        $stmt->execute([CaptchaContext::settingKey(self::CAPTCHA_CONTEXT)]);
    }

    /**
     * Filter-Beispiel: zeigt das Formular nur, wenn dem Pferd über die
     * Deckstation eine E-Mail-Adresse hinterlegt ist - `$horse` enthält das
     * bereits (PublicController::horseDetail() joint `contacts` als
     * `station_email`/`station_name`), keine eigene Datenbankabfrage nötig.
     *
     * Wichtig: Der Kern übergibt dem Hook ÖFFENTLICH GEFILTERTE Daten. Die
     * `station_*`-Felder sind null, wenn die Station unveröffentlicht oder
     * gelöscht ist oder der Gast-Gruppe `contacts.view` fehlt (bis v0.7 hiess
     * das Rechte-Modul `breeding_stations`, seit Framework#336 deckt
     * `contacts` beide Datensatzarten ab). `$horse['breeding_station_id']`
     * bleibt dabei gesetzt und taugt deshalb NICHT als Bedingung.
     *
     * Seit #336 kommt eine zweite Bedingung dazu, und sie ist der eigentliche
     * Grund, warum hier `station_email` geprüft wird und nicht die
     * Verknüpfung: Der Kern liefert `station_email` nur noch bei
     * `contacts.contact_public = 1`. Bis v0.7 schützte die Trennung der
     * Tabellen - eine Deckstation war eine Geschäftsadresse in einer eigenen
     * Tabelle ohne PII. Nach der Zusammenlegung steht dieselbe Spalte auch
     * Privatpersonen zur Verfügung, und ohne ausdrückliche Freigabe kommt sie
     * gar nicht erst an. Siehe docs/plugin-development.md des Kerns,
     * Abschnitt "Was in $horse und $horsePersons steht", und
     * docs/kontaktliste-umstellung.md, "Datenschutz-Grenze".
     */
    public function addDetailSection(array $sections, array $horse, array $horsePersons, ?array $pedigree): array {
        if (empty($horse['station_email'])) {
            return $sections;
        }

        // Geschlechts-Gate (#53): Eine Deckanfrage richtet sich an einen
        // HENGST. Für Stuten und Wallache erscheint kein Formular, auch wenn
        // sie einer Station mit E-Mail zugeordnet sind. NULL (unbekannt,
        // Altbestand) bleibt zugelassen - konsistent zur NULL-Regel des Kerns
        // (Framework #165); die Station-E-Mail bleibt der zweite Filter.
        if (in_array($horse['sex'] ?? null, ['mare', 'gelding'], true)) {
            return $sections;
        }

        $horseId = (int) $horse['id'];
        $csrfToken = htmlspecialchars(Router::generateCsrfToken(), ENT_QUOTES, 'UTF-8');

        $html = '<div style="margin-top:1rem;padding:1rem;background:var(--surface-muted);border-radius:var(--border-radius, 6px);">';
        $html .= '<h3 style="margin-top:0;">📨 Deckanfrage stellen</h3>';

        if (($_GET['deckanfrage'] ?? '') === 'erfolg') {
            $html .= '<p style="color:var(--success-fg);background:var(--success-soft-bg);padding:0.6rem;border-radius:var(--border-radius, 4px);">Ihre Anfrage wurde erfolgreich versendet.</p>';
        } elseif (($_GET['deckanfrage'] ?? '') === 'captcha') {
            // Eigener Status, nicht "fehler" (#351): Die Sicherheitsfrage ist
            // das Einzige, was der Besucher selbst beheben kann - sagt man ihm
            // stattdessen "später erneut versuchen", versucht er es später
            // erneut und scheitert wieder.
            $html .= '<p style="color:var(--danger-fg);background:var(--danger-soft-bg);padding:0.6rem;border-radius:var(--border-radius, 4px);">Die Sicherheitsfrage wurde nicht korrekt beantwortet. Bitte lösen Sie die neue Aufgabe und senden Sie die Anfrage erneut.</p>';
        } elseif (($_GET['deckanfrage'] ?? '') === 'fehler') {
            $html .= '<p style="color:var(--danger-fg);background:var(--danger-soft-bg);padding:0.6rem;border-radius:var(--border-radius, 4px);">Ihre Anfrage konnte nicht versendet werden. Bitte versuchen Sie es später erneut.</p>';
        }

        $html .= '<form method="POST" action="/plugin/deckanfrage/anfrage">';
        $html .= '<input type="hidden" name="csrf_token" value="' . $csrfToken . '">';
        $html .= '<input type="hidden" name="horse_id" value="' . $horseId . '">';
        // Honeypot: für Menschen unsichtbares Feld - füllt ein Bot es aus, wird die Anfrage
        // im Controller stillschweigend verworfen, ohne dem Bot einen Hinweis darauf zu geben.
        //
        // Der Feldname kommt seit #351 aus Captcha::HONEYPOT_FIELD statt aus
        // einem eigenen ("webseite"): Damit prüft der Controller mit
        // Captcha::honeypotTripped() genau das Feld, das hier gerendert wird.
        // Zwei Stellen, die sich denselben Namen unabhängig voneinander
        // merken, gehen irgendwann auseinander - und ein Honeypot, dessen
        // Prüfung ins Leere greift, sieht funktionierend aus und ist keiner.
        $honeypot = htmlspecialchars(Captcha::HONEYPOT_FIELD, ENT_QUOTES, 'UTF-8');
        $html .= '<div style="position:absolute;left:-9999px;" aria-hidden="true">'
            . '<label for="deckanfrage-' . $honeypot . '">Webseite (bitte leer lassen)</label>'
            . '<input type="text" id="deckanfrage-' . $honeypot . '" name="' . $honeypot . '" tabindex="-1" autocomplete="off">'
            . '</div>';

        $html .= '<label style="display:block;margin-top:0.5rem;font-size:0.9em;">Ihr Name<br>'
            . '<input type="text" name="requester_name" required style="width:100%;padding:0.4rem;margin-top:0.2rem;"></label>';
        $html .= '<label style="display:block;margin-top:0.5rem;font-size:0.9em;">Ihre E-Mail-Adresse<br>'
            . '<input type="email" name="requester_email" required style="width:100%;padding:0.4rem;margin-top:0.2rem;"></label>';
        $html .= '<label style="display:block;margin-top:0.5rem;font-size:0.9em;">Nachricht<br>'
            . '<textarea name="message" required rows="4" style="width:100%;padding:0.4rem;margin-top:0.2rem;"></textarea></label>';

        // Spam-Schutz des Kerns (Framework#351). `renderField()` liefert das
        // Fragment des Anbieters, den der Betreiber für DIESES Formular
        // gewählt hat - ohne eigene Wahl die globale, ohne globale die
        // eingebaute Rechenaufgabe. Es kommt in jedem Fall etwas zurück; ein
        // Formular ohne Schutz gibt es hier nicht mehr.
        $html .= Captcha::renderField(self::kernEinstellungen(), self::CAPTCHA_CONTEXT);

        $html .= '<p style="font-size:0.8em;color:var(--text-muted);margin-top:0.4rem;">Ihre Angaben werden zur Bearbeitung der Deckanfrage an die Deckstation weitergeleitet.</p>';
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
 * Kern-Formulare, Honeypot, IP-basiertes Rate-Limiting und seit Framework#351
 * die Sicherheitsfrage des Kerns gegen Spam.
 *
 * Die Reihenfolge der Hürden ist nicht beliebig: erst CSRF, dann Honeypot
 * (ein Bot soll gar nicht erst weiterkommen), dann das Rate-Limit, dann die
 * Sicherheitsfrage - und erst danach irgendetwas, das vom angefragten Pferd
 * abhängt. Alles, was vor der Pferde-Abfrage liegt, kann über seinen
 * Rückgabestatus nichts über die Existenz eines Pferdes verraten.
 */
class AnfrageController extends BaseController {

    public function submit(): void {
        $horseId = !empty($_POST['horse_id']) ? (int) $_POST['horse_id'] : null;

        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }

        // Honeypot ausgefüllt: stillschweigend verwerfen, als Erfolg ausgeben.
        // Geprüft wird über den Kern-Helfer, damit Feldname und Prüfung nicht
        // auseinanderlaufen können (siehe Plugin::addDetailSection()).
        if (Captcha::honeypotTripped($_POST)) {
            Captcha::clear();
            $this->redirectBack($horseId, 'erfolg');
        }

        $ip = ClientIp::resolve();
        if (RateLimiter::tooManyAttempts($ip, Plugin::RATE_LIMIT_TYPE, 5, 3600)) {
            $this->redirectBack($horseId, 'fehler');
        }
        RateLimiter::recordAttempt($ip, Plugin::RATE_LIMIT_TYPE);

        // Sicherheitsfrage VOR jeder Verarbeitung und vor der Pferde-Abfrage
        // (Framework#351): Sie darf nichts darüber verraten, ob das Pferd
        // existiert, veröffentlicht ist oder eine erreichbare Station hat -
        // sonst wäre ausgerechnet der Spam-Schutz das Existenz-Orakel, das
        // der Rest dieses Controllers sorgfältig vermeidet.
        //
        // Eigener Rückgabestatus 'captcha' statt 'fehler': Das ist der einzige
        // Fehlerfall, den der Besucher selbst beheben kann.
        if (Captcha::verify($this->settings, Plugin::CAPTCHA_CONTEXT, $_POST) !== Captcha::OK) {
            $this->redirectBack($horseId, 'captcha');
        }

        $requesterName = trim($_POST['requester_name'] ?? '');
        $requesterEmail = trim($_POST['requester_email'] ?? '');
        $message = trim($_POST['message'] ?? '');

        if (!$horseId || $requesterName === '' || $message === '' || !filter_var($requesterEmail, FILTER_VALIDATE_EMAIL)) {
            $this->redirectBack($horseId, 'fehler');
        }

        $db = Database::getInstance();
        // Diese Abfrage bestimmt den EMPFÄNGER einer E-Mail, die Name,
        // Adresse und Anliegen eines Dritten enthält. Sie muss deshalb Zeile
        // für Zeile dieselbe Sichtbarkeitsregel anwenden wie
        // PublicController::horseDetail(), aus der das Formular seine
        // Anzeigebedingung bezieht - sonst liesse sich per direktem POST
        // (gueltiger CSRF-Token von jeder Seite) an jemanden versenden, dem
        // die oeffentliche Seite die Adresse bewusst vorenthaelt.
        //
        // Seit Framework#336 (Addons#138) sind das drei Bedingungen statt
        // einer, und die dritte ist neu:
        //
        // 1. bs.deleted_at IS NULL und bs.is_published = 1 - wie bisher
        //    (Kern-#122): keine geloeschte, keine unveroeffentlichte Station.
        // 2. Die Tabelle heisst `contacts`. `horses.breeding_station_id` zeigt
        //    weiter auf sie, nur eben auf einen Kontakt in der ROLLE
        //    Deckstation.
        // 3. bs.contact_public = 1 - die E-Mail-Adresse gilt nur mit
        //    ausdruecklicher Freigabe als zustellbar. Bis v0.7 war eine
        //    Deckstation eine Geschaeftsadresse in einer eigenen Tabelle ohne
        //    PII, und `email` war dort schlicht oeffentlich. Seit der
        //    Zusammenlegung steht dieselbe Spalte auch bei Privatpersonen, und
        //    der Kern liefert `station_email` nur noch bei gesetzter Freigabe
        //    (siehe docs/kontaktliste-umstellung.md, "Datenschutz-Grenze").
        //    Ohne diese Zeile bliebe das Formular zwar unsichtbar, ein
        //    Direkt-POST schickte die Anfrage aber weiterhin an eine Adresse,
        //    deren Inhaber der Weitergabe nicht zugestimmt hat.
        //
        // Ausdruecklich NICHT selektiert werden die uebrigen Kontaktspalten:
        // Gebraucht werden Name und E-Mail-Adresse, alles Weitere waere ein
        // `SELECT *` mit Anlauf.
        $stmt = $db->prepare(
            'SELECT h.id, h.name, h.sex,
                    CASE WHEN bs.contact_public = 1 THEN bs.email END AS station_email,
                    bs.name AS station_name
             FROM horses h
             LEFT JOIN contacts bs ON h.breeding_station_id = bs.id AND bs.deleted_at IS NULL AND bs.is_published = 1
             WHERE h.id = ? AND h.deleted_at IS NULL AND h.is_published = 1'
        );
        $stmt->execute([$horseId]);
        $horse = $stmt->fetch();

        // Die Rechte der Gast-Gruppe zaehlen genauso mit wie in
        // PublicController::horseDetail(): Ohne `horses.view` liefert die
        // Detailseite 404, ohne `contacts.view` nullt sie alle station_*-Felder
        // (Rechte-Modul seit #336 `contacts`, frueher `breeding_stations`).
        // In beiden Faellen erscheint kein Formular - und in beiden Faellen
        // darf ein Direkt-POST nichts versenden.
        if (!$this->hasPermission('horses', 'view') || !$this->hasPermission('contacts', 'view')) {
            $this->redirectBack($horseId, 'erfolg');
        }

        // Nicht auffindbares/unveröffentlichtes Pferd: stillschweigend verwerfen
        // und wie beim Honeypot "erfolg" melden - der Redirect-Status darf kein
        // Existenz-Orakel für im Kern verborgene Pferde-IDs sein. Dasselbe
        // Muster für das Geschlechts-Gate (#53): Anzeige und Verarbeitung
        // wenden dieselbe Regel an, ein direkter POST auf eine Stute läuft
        // ununterscheidbar ins Leere.
        if (!$horse || empty($horse['station_email']) || in_array($horse['sex'] ?? null, ['mare', 'gelding'], true)) {
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

        // Protokoll (#134, seit #352 über PluginAudit): Bis hierher schrieb
        // dieses Addon eine Zeile in die Datenbank und verschickte eine
        // E-Mail, ohne dass davon irgendwo eine Spur blieb - wer sich das
        // Protokoll ansah, durfte annehmen, es sei nichts geschehen.
        //
        // `PluginAudit::log()` statt `AuditLogger::log()`, weil es die
        // Kategorie aus dem Slug ableitet und diesen gegen die tatsächlich
        // geladenen Addons prüft: Ein Eintrag, der über seinen Urheber lügen
        // kann, ist als Nachweis wertlos. Die Auswahlliste der
        // Protokollansicht entsteht aus SELECT DISTINCT category und nimmt die
        // Kategorie von selbst auf.
        //
        // Bewusst OHNE Name, E-Mail-Adresse und Nachricht des Absenders: Das
        // Protokoll wird dauerhaft aufbewahrt und nicht mit den Anfragen
        // gelöscht - es soll die HANDLUNG nachweisen, nicht die Anfrage ein
        // zweites Mal speichern. Was den Eintrag brauchbar macht, ist der
        // Bezug (welche Anfrage, welches Pferd) und ob die Weiterleitung
        // geklappt hat; die Inhalte stehen in der Anfrage selbst.
        PluginAudit::log(
            Plugin::SLUG,
            'Deckanfrage eingegangen',
            "Anfrage #{$requestId}, Pferd #{$horseId} ({$horse['name']})",
            'Weiterleitung an die Deckstation: ' . ($sent ? 'zugestellt' : 'fehlgeschlagen')
        );

        $this->redirectBack($horseId, $sent ? 'erfolg' : 'fehler');
    }

    private function redirectBack(?int $horseId, string $status): void {
        header('Location: /horse?id=' . (int) $horseId . '&deckanfrage=' . $status);
        exit;
    }
}
