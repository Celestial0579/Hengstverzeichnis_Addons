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
use App\Helper\MediaUrl;
use App\Plugin\HookManager;
use App\Plugin\PluginPage;
use App\Router;
use App\Security\ClientIp;
use App\Security\RateLimiter;
use App\Service\Mailer;
use PDO;

class Plugin {

    /**
     * Request-weiter Cache der horse_ids mit Inserat (Framework#222):
     * Beim Leeren des Papierkorbs feuert der Kern horse.before_delete für
     * JEDES Pferd einzeln - eine eigene SELECT-Query je Pferd summierte sich
     * dort auf Hunderte Round-Trips in einem Request. Stattdessen werden die
     * horse_ids aller Inserate beim ersten Hook-Aufruf EINMAL geladen
     * (array_flip für O(1)-Lookups); jeder Folgeaufruf ist ein
     * Array-Zugriff. null = noch nicht geladen.
     *
     * @var array<int, int>|null
     */
    private ?array $listingHorseIds = null;

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
        // Seit #119 der einzige Pflegeweg: der Abschnitt im
        // Bearbeitungsformular des Pferdes. Die addoneigene Verwaltungsseite
        // und ihre Dashboard-Kachel sind entfallen - sie verlangten, dasselbe
        // Pferd über eine zweite Suche erneut herauszusuchen, obwohl man in
        // dessen Datensatz bereits steht. Die ÖFFENTLICHE Börse (/liste)
        // bleibt: Sie ist der Zweck des Addons und kein Doppel der Pferdeseite.
        $hooks->addFilter('horse.edit_sections', [$this, 'addEditSection']);
        // Lösch-/Papierkorb-Hooks des Kerns (#51 / Framework #164): Das Inserat
        // selbst bleibt bei Soft-Delete bewusst UNVERÄNDERT gespeichert - die
        // öffentliche Sichtbarkeit hängt ohnehin am Pferd (JOIN-Regel, siehe
        // ListeController), und eine Wiederherstellung bringt das Inserat so
        // verlustfrei zurück. Die Hooks schreiben stattdessen einen
        // Audit-Log-Eintrag, damit die Diskrepanz "Inserat gespeichert,
        // Börse zeigt es nicht" für Admins nachvollziehbar dokumentiert ist
        // (den Status zeigt seit #119 zusätzlich der Abschnitt im
        // Bearbeitungsformular des Pferdes).
        $hooks->addAction('horse.trashed', [$this, 'onHorseTrashed']);
        $hooks->addAction('horse.restored', [$this, 'onHorseRestored']);
        $hooks->addAction('horse.before_delete', [$this, 'onHorseBeforeDelete']);
    }

    public function onHorseTrashed(int $horseId, array $horse): void {
        if (!$this->hasActiveListing($horseId)) {
            return;
        }
        \App\Service\AuditLogger::log(
            'Verkaufsbörse: Inserat durch Papierkorb öffentlich unsichtbar',
            'plugin',
            'Pferd ID ' . $horseId . ' (' . ($horse['name'] ?? 'unbekannt') . '): Das Inserat bleibt gespeichert und erscheint wieder, sobald das Pferd wiederhergestellt wird.'
        );
    }

    public function onHorseRestored(int $horseId, array $horse): void {
        if (!$this->hasActiveListing($horseId)) {
            return;
        }
        \App\Service\AuditLogger::log(
            'Verkaufsbörse: Inserat nach Wiederherstellung wieder sichtbar',
            'plugin',
            'Pferd ID ' . $horseId . ' (' . ($horse['name'] ?? 'unbekannt') . '): Das Inserat ist wieder öffentlich (sofern nicht abgelaufen und das Pferd veröffentlicht ist).'
        );
    }

    public function onHorseBeforeDelete(int $horseId, array $horse, bool $permanent): void {
        // Nur das ENDGÜLTIGE Löschen ist hier relevant - danach entfernt der
        // FK-ON-DELETE-CASCADE die Inserat-Zeile mit, und dieser Eintrag ist
        // die letzte Spur davon. Den Papierkorb-Fall behandelt onHorseTrashed.
        if (!$permanent || !$this->hasActiveListing($horseId)) {
            return;
        }
        \App\Service\AuditLogger::log(
            'Verkaufsbörse: Inserat wird mit dem Pferd endgültig gelöscht',
            'plugin',
            'Pferd ID ' . $horseId . ' (' . ($horse['name'] ?? 'unbekannt') . '): Die Inserat-Zeile entfällt über den Fremdschlüssel (ON DELETE CASCADE).'
        );
    }

    /**
     * Batch-tauglich (Framework#222): lädt beim ersten Aufruf im Request die
     * horse_ids ALLER Inserate einmal in $listingHorseIds; danach ist jeder
     * Aufruf ein reiner Array-Lookup. Die Hooks feuern nur bei
     * Bestandsänderungen am Pferd (Papierkorb/Restore/Löschen) - Inserate
     * ändern sich in denselben Requests nicht, der Cache veraltet also
     * innerhalb eines Requests nicht. Beim before_delete-Batch des
     * Papierkorbs wird ohnehin VOR den DELETEs gehookt.
     */
    private function hasActiveListing(int $horseId): bool {
        if ($this->listingHorseIds === null) {
            $ids = Database::getInstance()
                ->query('SELECT horse_id FROM `plugin_verkaufsboerse_listings`')
                ->fetchAll(PDO::FETCH_COLUMN);
            $this->listingHorseIds = array_flip(array_map('intval', $ids));
        }
        return isset($this->listingHorseIds[$horseId]);
    }

    /**
     * Framework-Hook (#75): Der PluginManager ruft install() bei der
     * Aktivierung und nach einem Addon-Update genau einmal auf - das
     * DDL-Statement läuft damit nicht mehr in jedem Request.
     */
    public function install(): void {
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

        $html = '<div style="margin-top:1rem;padding:1rem;background:var(--info-soft-bg);border:1px solid var(--warning-fg);border-radius:var(--border-radius, 6px);">';
        $html .= '<h3 style="margin-top:0;">🏷️ Zum Verkauf - ' . htmlspecialchars($priceText, ENT_QUOTES, 'UTF-8') . '</h3>';

        if (!empty($listing['description'])) {
            $html .= '<p>' . nl2br(htmlspecialchars((string) $listing['description'], ENT_QUOTES, 'UTF-8')) . '</p>';
        }

        if (($_GET['verkaufsanfrage'] ?? '') === 'erfolg') {
            $html .= '<p style="color:var(--success-fg);background:var(--success-soft-bg);padding:0.6rem;border-radius:var(--border-radius, 4px);">Ihre Anfrage wurde erfolgreich versendet.</p>';
        } elseif (($_GET['verkaufsanfrage'] ?? '') === 'fehler') {
            $html .= '<p style="color:var(--danger-fg);background:var(--danger-soft-bg);padding:0.6rem;border-radius:var(--border-radius, 4px);">Ihre Anfrage konnte nicht versendet werden. Bitte versuchen Sie es später erneut.</p>';
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

    /**
     * Filter (#119, Framework#255): hängt die Anzeigenpflege direkt in das
     * Admin-Bearbeitungsformular des Hengstes - wie zuvor schon bei
     * `titel-praemierungen` (#117).
     *
     * Die `horse_id` ist hier durch die Seite bereits gegeben; die
     * Pferdeauswahl der entfallenen Verwaltungsseite (`horse_q` samt eigener
     * JSON-Suche) fällt damit ersatzlos weg (Addons#125). Geladen wird nur
     * noch das eine Inserat dieses Pferdes - `horse_id` ist UNIQUE, es kann
     * höchstens eines geben, weshalb derselbe Abschnitt anlegen UND ändern
     * trägt (store() ist ein Upsert).
     *
     * Der Sichtbarkeits-Hinweis aus #51 zieht mit um: Er beantwortet die
     * Frage "warum steht mein Inserat nicht in der Börse?" genau dort, wo
     * das Inserat gepflegt wird. Ohne ihn nähme #119 eine Fähigkeit weg,
     * statt nur einen doppelten Weg zu schließen - die bestandsweite
     * Übersicht dagegen beantwortet die öffentliche Börse (/liste).
     */
    public function addEditSection(array $sections, array $horse): array {
        // Das Bearbeitungsformular verlangt horses.edit, diese Daten aber
        // verkaufsboerse.manage. Ohne diese Prüfung sähe ein Redakteur ein
        // Formular, das beim Absenden 403 liefert. Fail-closed.
        if (!\App\Permission\GroupMembership::hasPermission(
            (int) ($_SESSION['user_id'] ?? 0), 'verkaufsboerse', 'manage'
        )) {
            return $sections;
        }

        $horseId = (int) ($horse['id'] ?? 0);
        if ($horseId <= 0) {
            return $sections;
        }

        // Bewusst OHNE den Ablauf-Filter der öffentlichen Börse: Wer pflegt,
        // muss auch ein abgelaufenes Inserat noch sehen und verlängern können.
        $stmt = Database::getInstance()->prepare(
            'SELECT id, price, price_on_request, description, contact_email, listed_until
             FROM `plugin_verkaufsboerse_listings` WHERE horse_id = :id'
        );
        $stmt->execute(['id' => $horseId]);
        $listing = $stmt->fetch(PDO::FETCH_ASSOC);

        $csrfToken = Router::generateCsrfToken();
        $esc = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $html = '<h3 style="margin-top:0;">🏷️ Verkaufsanzeige</h3>';

        if ($listing) {
            // Warum die öffentliche Börse dieses Inserat ggf. NICHT zeigt
            // (#51) - dieselben Bedingungen wie im JOIN von
            // ListeController::show(). `$horse` ist hier der ROHE Datensatz
            // (siehe docs/plugin-development.md), deleted_at und is_published
            // stehen also unmittelbar zur Verfügung.
            if (!empty($horse['deleted_at'])) {
                $status = '<span style="color:var(--danger-fg);font-weight:bold;">Pferd im Papierkorb - Inserat öffentlich unsichtbar</span>';
            } elseif (empty($horse['is_published'])) {
                $status = '<span style="color:var(--warning-fg);font-weight:bold;">Pferd unveröffentlicht - Inserat öffentlich unsichtbar</span>';
            } elseif (!empty($listing['listed_until']) && $listing['listed_until'] < date('Y-m-d')) {
                $status = '<span style="color:var(--warning-fg);font-weight:bold;">abgelaufen - öffentlich unsichtbar</span>';
            } else {
                $status = '<span style="color:var(--success-fg);">öffentlich sichtbar</span>';
            }

            $html .= '<p style="display:flex;justify-content:space-between;align-items:center;gap:1rem;">'
                . '<span>Status: ' . $status . '</span>'
                . '<form method="POST" action="/plugin/verkaufsboerse/verwaltung/delete" style="margin:0;"'
                . ' onsubmit="return confirm(\'Inserat wirklich entfernen?\');">'
                . '<input type="hidden" name="csrf_token" value="' . $esc($csrfToken) . '">'
                . '<input type="hidden" name="id" value="' . (int) $listing['id'] . '">'
                . '<button type="submit" class="btn btn-secondary" style="color:var(--danger-fg);">Inserat entfernen</button>'
                . '</form></p>';
        } else {
            $html .= '<p style="color:var(--text-muted);">Für dieses Pferd läuft keine Verkaufsanzeige.</p>';
        }

        // Eigenes Formular mit eigener POST-Route: Der Abschnitt steht
        // ausserhalb des Kern-Formulars (Framework#255), der Speichern-Knopf
        // oben speichert diese Felder also NICHT mit.
        //
        // Ein Feld `zurueck` gibt es nicht: Alle Formulare dieses Abschnitts
        // stehen im Bearbeitungsformular des Pferdes, der Rückweg steht damit
        // fest (siehe redirectBack()).
        //
        // Die Feld-`id`s tragen das Präfix `vb_`: Das Kern-Formular auf
        // derselben Seite führt eigene Felder (u. a. `description`), und zwei
        // gleiche id-Werte in einem Dokument hängen das <label> an das falsche
        // Feld. Die `name`-Attribute bleiben unverändert - sie gelten je
        // Formular und sind der Vertrag mit store().
        $html .= '<form method="POST" action="/plugin/verkaufsboerse/verwaltung/store">';
        $html .= '<input type="hidden" name="csrf_token" value="' . $esc($csrfToken) . '">';
        $html .= '<input type="hidden" name="horse_id" value="' . $horseId . '">';
        $html .= '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;">';
        $html .= '<div class="form-group"><label for="vb_price">Preis (€, leer = auf Anfrage)</label>'
            . '<input type="number" step="0.01" min="0" name="price" id="vb_price" class="form-control"'
            . ' value="' . ($listing && $listing['price'] !== null ? $esc($listing['price']) : '') . '"></div>';
        $html .= '<div class="form-group"><label for="vb_contact_email">Kontakt-E-Mail</label>'
            . '<input type="email" name="contact_email" id="vb_contact_email" class="form-control" maxlength="150" required'
            . ' value="' . $esc($listing['contact_email'] ?? '') . '"></div>';
        $html .= '</div>';
        $html .= '<div class="form-group"><label for="vb_description">Beschreibung</label>'
            . '<textarea name="description" id="vb_description" class="form-control" rows="3">'
            . $esc($listing['description'] ?? '') . '</textarea></div>';
        $html .= '<div class="form-group"><label for="vb_listed_until">Gelistet bis (optional)</label>'
            . '<input type="date" name="listed_until" id="vb_listed_until" class="form-control"'
            . ' value="' . $esc($listing['listed_until'] ?? '') . '"></div>';
        // Beschriftung bewusst nicht "Speichern": Auf der Seite gibt es zwei
        // Knöpfe, und wer hier drückt, verliert ungespeicherte Stammdaten oben.
        $html .= '<p><button type="submit" class="btn">'
            . ($listing ? 'Anzeige aktualisieren' : 'Anzeige veröffentlichen') . '</button>'
            . ' <span style="color:var(--text-muted);font-size:0.85rem;">Änderungen an den Stammdaten oben bitte zuerst speichern.</span></p>';
        $html .= '</form>';

        $sections[] = $html;
        return $sections;
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
     * Von den drei früheren GET-Routen bleibt genau eine (#119): die
     * öffentliche Börse. Die Verwaltungsseite ist in den Pferdeabschnitt
     * gewandert, und die addoneigene Pferdesuche (`/suche`) diente allein der
     * Pferdeauswahl auf dieser Seite - der Kern liefert sie seit
     * Framework#341 unter /admin/horses/search (Addons#125).
     *
     * @return array<int, array{method:string, path:string, callback:array}>
     */
    public function routes(): array {
        return [
            ['method' => 'GET', 'path' => '/liste', 'callback' => [ListeController::class, 'show']],
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
/**
 * Seitennummer aus der Anfrage - validiert, nicht umgedeutet.
 *
 * Eigene Klasse, weil sie einmal von ZWEI Controllern gebraucht wurde
 * (oeffentliche Liste und Verwaltung). Als private Methode in einem der beiden
 * waere sie im anderen ein Fatal - genau das ist beim ersten Anlauf passiert
 * und hat den Funktionstest mit HTTP 500 rot gemacht. Seit #119 blaettert nur
 * noch die oeffentliche Boerse; die Klasse bleibt trotzdem eigenstaendig, denn
 * die Begruendung von damals gilt weiter, sobald ein zweiter Aufrufer kommt.
 *
 * filter_var mit FILTER_VALIDATE_INT lehnt ab, was keine Zahl IST; ein blosser
 * (int)-Cast machte aus "abc" eine 0 und aus "3x" eine 3. Der Cast ist
 * ausserdem fuer eine statische Analyse keine erkennbare Bereinigung - die
 * Seitennummer floss deshalb als "Nutzerdaten" bis in den HTML-Aufbau und
 * liess dort eine Injection-Regel anschlagen.
 */
final class Seitenzahl {

    private function __construct() {}

    public static function ausAnfrage(): int {
        $wert = filter_var(
            $_GET['seite'] ?? 1,
            FILTER_VALIDATE_INT,
            ['options' => ['default' => 1, 'min_range' => 1]]
        );
        return is_int($wert) ? $wert : 1;
    }
}


class ListeController extends BaseController {


    /** Inserate je Seite der öffentlichen Börse (#74). */
    private const LISTINGS_PER_PAGE = 50;

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
        // Paginiert (#74): vorher lud die Seite alle aktiven Inserate ohne
        // LIMIT und renderte jede Zeile ins HTML.
        $db = Database::getInstance();
        $totalListings = (int) $db->query(
            'SELECT COUNT(*)
             FROM `plugin_verkaufsboerse_listings` l
             JOIN horses h ON h.id = l.horse_id AND h.deleted_at IS NULL AND h.is_published = 1
             WHERE l.listed_until IS NULL OR l.listed_until >= CURDATE()'
        )->fetchColumn();
        $pageCount = max(1, (int) ceil($totalListings / self::LISTINGS_PER_PAGE));
        $page = min($pageCount, Seitenzahl::ausAnfrage());

        $listingsStmt = $db->prepare(
            'SELECT l.*, h.name AS horse_name, h.birth_year, h.color, h.image_url
             FROM `plugin_verkaufsboerse_listings` l
             JOIN horses h ON h.id = l.horse_id AND h.deleted_at IS NULL AND h.is_published = 1
             WHERE l.listed_until IS NULL OR l.listed_until >= CURDATE()
             ORDER BY l.listed_at DESC, l.id DESC
             LIMIT :limit OFFSET :offset'
        );
        $listingsStmt->bindValue('limit', self::LISTINGS_PER_PAGE, PDO::PARAM_INT);
        $listingsStmt->bindValue('offset', ($page - 1) * self::LISTINGS_PER_PAGE, PDO::PARAM_INT);
        $listingsStmt->execute();
        $listings = $listingsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Inhalt als Fragment im Haupt-Layout über PluginPage (Addons#66):
        // Header, Navigation, Theme-Umschalter und Grund-Styling kommen vom
        // Framework. Addon-spezifisch bleibt allein die Geometrie der
        // Inserats-Zeilen (Thumbnail-Raster) - Farben ausschließlich über
        // Theme-Variablen.
        $content = '<style>
            .verkaufsboerse-listing{display:flex;flex-wrap:wrap;gap:1rem;padding:1rem 0;border-bottom:1px solid var(--border-color);align-items:center;}
            .verkaufsboerse-listing img{width:100px;height:100px;object-fit:cover;border-radius:var(--border-radius, 6px);}
            .verkaufsboerse-listing h2{margin:0 0 0.3rem 0;font-size:1.1rem;}
            .verkaufsboerse-preis{font-weight:bold;color:var(--warning-fg);}
        </style>';
        $content .= '<div class="card">';
        $content .= '<h1>🏷️ Verkaufs-/Vermittlungsbörse</h1>';

        foreach ($listings as $listing) {
            $priceText = !empty($listing['price_on_request']) || $listing['price'] === null
                ? 'Preis auf Anfrage'
                : number_format((float) $listing['price'], 2, ',', '.') . ' €';

            $content .= '<div class="verkaufsboerse-listing">';
            // Über die geschützte Route des Kerns statt über den rohen
            // Spaltenwert: Sonst wird der Dateiname öffentlich bekannt, und das
            // Foto bleibt nach einer Depublikation weiter abrufbar.
            $bildUrl = MediaUrl::horseImage([
                'id' => $listing['horse_id'],
                'image_url' => $listing['image_url'] ?? null,
            ]);
            if ($bildUrl !== null) {
                $content .= '<img src="' . htmlspecialchars($bildUrl, ENT_QUOTES, 'UTF-8') . '" alt="">';
            }
            $content .= '<div>';
            $content .= '<h2><a href="/horse?id=' . (int) $listing['horse_id'] . '">' . htmlspecialchars((string) $listing['horse_name'], ENT_QUOTES, 'UTF-8') . '</a></h2>';
            $content .= '<div class="verkaufsboerse-preis">' . htmlspecialchars($priceText, ENT_QUOTES, 'UTF-8') . '</div>';
            if (!empty($listing['birth_year'])) {
                $content .= '<div>Geburtsjahr: ' . htmlspecialchars((string) $listing['birth_year'], ENT_QUOTES, 'UTF-8') . '</div>';
            }
            $content .= '</div></div>';
        }

        if (empty($listings)) {
            $content .= '<p>Aktuell keine Pferde in der Verkaufsbörse gelistet.</p>';
        }

        // Blätter-Leiste (#74): erscheint erst, wenn es mehr als eine Seite
        // gibt - die Ein-Seiten-Ansicht bleibt unverändert schlank.
        if ($pageCount > 1) {
            $content .= '<p>';
            if ($page > 1) {
                $content .= '<a class="btn btn-secondary" href="/plugin/verkaufsboerse/liste?seite=' . ($page - 1) . '">&laquo; Zurück</a> ';
            }
            $content .= 'Seite ' . (int) $page . ' von ' . (int) $pageCount . ' (' . (int) $totalListings . ' Inserate)';
            if ($page < $pageCount) {
                $content .= ' <a class="btn btn-secondary" href="/plugin/verkaufsboerse/liste?seite=' . ($page + 1) . '">Weiter &raquo;</a>';
            }
            $content .= '</p>';
        }

        $content .= '</div>';

        PluginPage::render('Verkaufsbörse', $content);
    }
}

/**
 * Admin-Verwaltung der Inserate. Zugriffsschutz über die selbst
 * registrierte Berechtigung "verkaufsboerse.manage". `horse_id` ist in der
 * Tabelle UNIQUE - store() ist daher ein Upsert (ein Pferd hat höchstens ein
 * aktives Inserat, erneutes Speichern aktualisiert es statt einen Fehler
 * durch den doppelten Schlüssel auszulösen).
 *
 * Seit #119 hat der Controller keine eigene Seite mehr: Beide Routen sind die
 * Ziele der Formulare im Abschnitt des Pferdeformulars
 * (Plugin::addEditSection). Der Name bleibt trotzdem `VerwaltungController` -
 * er steckt in den Pfaden `/verwaltung/store` und `/verwaltung/delete`, und
 * die umzubenennen hieße, die Formulare mitzuziehen, ohne dass sich dadurch
 * irgendetwas verbesserte.
 */
class VerwaltungController extends BaseController {

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
        $this->requirePermission('verkaufsboerse', 'manage');
    }

    public function store(): void {
        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }

        $db = Database::getInstance();

        // Die horse_id kommt seit #119 ausschließlich aus dem Aufrufkontext der
        // Pferdeseite (verstecktes Feld). Die frühere Auflösung eines
        // getippten Pferdenamens (`horse_q`) ist mit der Verwaltungsseite
        // entfallen - es gibt kein Textfeld mehr, das sie füllen könnte
        // (Addons#125).
        //
        // Existenz trotzdem prüfen: Ein erfundener Wert liefe sonst in den
        // FOREIGN-KEY-Fehler und damit in eine 500er-Seite, obwohl das schlicht
        // eine ungültige Eingabe ist.
        $horseId = !empty($_POST['horse_id']) ? (int) $_POST['horse_id'] : null;
        if ($horseId !== null && !self::pferdExistiert($db, $horseId)) {
            $horseId = null;
        }

        $contactEmail = trim($_POST['contact_email'] ?? '');

        if ($horseId && filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
            $price = ($_POST['price'] ?? '') !== '' ? (float) $_POST['price'] : null;

            $stmt = $db->prepare(
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

        $this->redirectBack($horseId);
    }

    public function delete(): void {
        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }

        $db = Database::getInstance();
        $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;

        // Die horse_id VOR dem DELETE aus der Zeile lesen - danach ist sie
        // nicht mehr zu holen, und dem POST allein ist sie nicht zu glauben:
        // Sie entscheidet über den Rückweg, ein manipulierter Wert schickte
        // den Benutzer in den Datensatz eines fremden Pferdes.
        $horseId = null;
        if ($id) {
            $stmt = $db->prepare('SELECT horse_id FROM `plugin_verkaufsboerse_listings` WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $found = $stmt->fetchColumn();
            $horseId = $found !== false ? (int) $found : null;

            $stmt = $db->prepare('DELETE FROM `plugin_verkaufsboerse_listings` WHERE id = :id');
            $stmt->execute(['id' => $id]);
        }

        $this->redirectBack($horseId);
    }

    /** Gibt es dieses Pferd (und ist es nicht im Papierkorb)? */
    private static function pferdExistiert(PDO $db, int $horseId): bool {
        $stmt = $db->prepare('SELECT 1 FROM horses WHERE id = :id AND deleted_at IS NULL');
        $stmt->execute(['id' => $horseId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Rückweg nach dem Speichern/Löschen. Bewusst KEINE übergebene URL,
     * sondern eine feste Adresse plus geprüfter Integer - eine mitgeschickte
     * Zieladresse wäre ein offener Redirect.
     *
     * Seit #119 gibt es dafür keinen Schalter mehr: Beide Formulare stehen im
     * Bearbeitungsformular des Pferdes, dorthin führt der Weg also immer
     * zurück. Nur wenn die horse_id nicht zu ermitteln war (POST von Hand,
     * Zeile inzwischen gelöscht), bleibt die Pferdeliste - die frühere
     * Verwaltungsseite gibt es nicht mehr, ein Verweis auf sie endete in 404.
     */
    private function redirectBack(?int $horseId): never {
        header('Location: ' . ($horseId !== null && $horseId > 0
            ? '/admin/horses/edit?id=' . $horseId
            : '/admin/horses'));
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
        header('Location: /horse?id=' . (int) $horseId . '&verkaufsanfrage=' . $status);
        exit;
    }
}
