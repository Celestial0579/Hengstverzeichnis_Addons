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
        $this->ensureTable();
        $hooks->addFilter('horse.detail_sections', [$this, 'addDetailSection']);
        $hooks->addFilter('admin.dashboard_tiles', [$this, 'addDashboardTile']);
        // Lösch-/Papierkorb-Hooks des Kerns (#51 / Framework #164): Das Inserat
        // selbst bleibt bei Soft-Delete bewusst UNVERÄNDERT gespeichert - die
        // öffentliche Sichtbarkeit hängt ohnehin am Pferd (JOIN-Regel, siehe
        // ListeController), und eine Wiederherstellung bringt das Inserat so
        // verlustfrei zurück. Die Hooks schreiben stattdessen einen
        // Audit-Log-Eintrag, damit die Diskrepanz "Verwaltung listet es,
        // Börse zeigt es nicht" für Admins nachvollziehbar dokumentiert ist
        // (den Status zeigt zusätzlich die Verwaltungsansicht je Inserat).
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
            Database::getInstance()->query('SELECT 1 FROM `plugin_verkaufsboerse_listings` LIMIT 1');
        } catch (\Throwable $e) {
            $this->install();
        }
        $checked = true;
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
            // Serverseitige Pferdesuche für die Datalist im Formular (#74,
            // Muster Framework-Katalog): JSON, max. 50 Treffer, nur mit
            // verkaufsboerse.manage (Konstruktor-Schutz des Controllers).
            ['method' => 'GET', 'path' => '/suche', 'callback' => [VerwaltungController::class, 'suche']],
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
        $page = min($pageCount, max(1, (int) ($_GET['seite'] ?? 1)));

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
            .verkaufsboerse-listing{display:flex;gap:1rem;padding:1rem 0;border-bottom:1px solid var(--border-color);align-items:center;}
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
            if (!empty($listing['image_url'])) {
                $content .= '<img src="' . htmlspecialchars((string) $listing['image_url'], ENT_QUOTES, 'UTF-8') . '" alt="">';
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
            $content .= 'Seite ' . $page . ' von ' . $pageCount . ' (' . $totalListings . ' Inserate)';
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
 */
class VerwaltungController extends BaseController {

    /** Treffer-Deckel der Datalist-Suche (#74). */
    private const SEARCH_LIMIT = 50;

    /** Inserate je Verwaltungsseite (#74). */
    private const LISTINGS_PER_PAGE = 50;

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
        $this->requirePermission('verkaufsboerse', 'manage');
    }

    public function index(): void {
        $db = Database::getInstance();

        // Bewusst OHNE Sichtbarkeitsfilter (anders als die öffentliche Börse,
        // siehe ListeController::show()): Die Verwaltung soll ALLE Inserate
        // zeigen - aber mit ausgewiesenem Status (#51), damit die Diskrepanz
        // "Verwaltung listet es, Börse zeigt es nicht" sichtbar wird, statt
        // still nebeneinander zu existieren (Pferd im Papierkorb/unveröffentlicht,
        // Inserat abgelaufen). Paginiert (#74): vorher lud die Seite alle
        // Inserate ohne LIMIT und renderte jede Zeile ins HTML.
        $totalListings = (int) $db->query('SELECT COUNT(*) FROM `plugin_verkaufsboerse_listings`')->fetchColumn();
        $pageCount = max(1, (int) ceil($totalListings / self::LISTINGS_PER_PAGE));
        $page = min($pageCount, max(1, (int) ($_GET['seite'] ?? 1)));

        $listingsStmt = $db->prepare(
            'SELECT l.*, h.name AS horse_name, h.deleted_at AS horse_deleted_at, h.is_published AS horse_is_published
             FROM `plugin_verkaufsboerse_listings` l
             JOIN horses h ON h.id = l.horse_id
             ORDER BY l.listed_at DESC, l.id DESC
             LIMIT :limit OFFSET :offset'
        );
        $listingsStmt->bindValue('limit', self::LISTINGS_PER_PAGE, PDO::PARAM_INT);
        $listingsStmt->bindValue('offset', ($page - 1) * self::LISTINGS_PER_PAGE, PDO::PARAM_INT);
        $listingsStmt->execute();
        $listings = $listingsStmt->fetchAll(PDO::FETCH_ASSOC);

        $csrfToken = Router::generateCsrfToken();

        // Die Seite rendert als Fragment im Framework-Layout
        // (App\Plugin\PluginPage, Addons#66) - Header, Navigation,
        // Theme-Umschalter, Markenfarben und style.css kommen zentral vom
        // Layout. Hier bleibt nur addon-spezifische Geometrie
        // (Formular-Raster), Farben ausschließlich über Theme-Variablen.
        $content = '<style>
            .verkaufsboerse-row{display:grid;grid-template-columns:1fr 1fr;gap:1rem;}
        </style>';
        $content .= '<div class="card">';
        $content .= '<h1>🏷️ Verkaufsbörse verwalten</h1>';

        $content .= '<h2>Inserat anlegen/aktualisieren</h2>';
        $content .= '<form method="POST" action="/plugin/verkaufsboerse/verwaltung/store">';
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

        $content .= '<div class="verkaufsboerse-row">';
        $content .= '<div class="form-group"><label for="price">Preis (€, leer = auf Anfrage)</label><input type="number" step="0.01" name="price" id="price" class="form-control"></div>';
        $content .= '<div class="form-group"><label for="contact_email">Kontakt-E-Mail</label><input type="email" name="contact_email" id="contact_email" class="form-control" required></div>';
        $content .= '</div>';

        $content .= '<div class="form-group"><label for="description">Beschreibung</label><textarea name="description" id="description" class="form-control" rows="3"></textarea></div>';
        $content .= '<div class="form-group"><label for="listed_until">Gelistet bis (optional)</label><input type="date" name="listed_until" id="listed_until" class="form-control"></div>';

        $content .= '<p><button type="submit" class="btn">Speichern</button></p>';
        $content .= '</form>';

        // Progressive Enhancement der Pferdesuche: lädt Vorschläge von
        // /plugin/verkaufsboerse/suche und mappt das gewählte Label auf die
        // ID im Hidden-Feld. Ohne fetch()/JS greift der No-JS-Fallback in
        // store().
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
        fetch("/plugin/verkaufsboerse/suche?q=" + encodeURIComponent(q))
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

        $content .= '<h2>Aktuelle Inserate</h2>';
        $content .= '<table><thead><tr><th>Pferd</th><th>Sichtbarkeit</th><th>Preis</th><th>Kontakt</th><th>Gelistet bis</th><th></th></tr></thead><tbody>';
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

            $content .= '<tr>';
            $content .= '<td>' . htmlspecialchars((string) $row['horse_name'], ENT_QUOTES, 'UTF-8') . '</td>';
            $content .= '<td>' . $visibility . '</td>';
            $content .= '<td>' . htmlspecialchars($priceText, ENT_QUOTES, 'UTF-8') . '</td>';
            $content .= '<td>' . htmlspecialchars((string) $row['contact_email'], ENT_QUOTES, 'UTF-8') . '</td>';
            $content .= '<td>' . htmlspecialchars((string) ($row['listed_until'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
            // Falschbefund, geprueft: Das ist HTML, kein SQL - und $page
            // ist keine Nutzereingabe mehr. Es entsteht aus
            // min($pageCount, max(1, (int) $_GET['seite'])), also
            // Ganzzahl-Cast plus Klemmung auf einen gueltigen
            // Seitenbereich. Semgreps Taint-Analyse erkennt den
            // (int)-Cast nicht als Bereinigung; derselbe Grund steht im
            // Kern an PublicController.php.
            // nosemgrep: php.lang.security.injection.tainted-sql-string.tainted-sql-string
            $content .= '<td><form method="POST" action="/plugin/verkaufsboerse/verwaltung/delete" style="margin:0;" onsubmit="return confirm(\'Inserat wirklich entfernen?\');">'
                . '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') . '">'
                . '<input type="hidden" name="seite" value="' . $page . '">'
                . '<input type="hidden" name="id" value="' . (int) $row['id'] . '">'
                . '<button type="submit" class="btn btn-secondary" style="color:var(--danger-fg);">Entfernen</button></form></td>';
            $content .= '</tr>';
        }
        if (empty($listings)) {
            $content .= '<tr><td colspan="6">Noch keine Inserate erfasst.</td></tr>';
        }
        $content .= '</tbody></table>';

        // Blätter-Leiste (#74): erscheint erst, wenn es mehr als eine Seite
        // gibt - die Ein-Seiten-Ansicht bleibt unverändert schlank.
        if ($pageCount > 1) {
            $content .= '<p>';
            if ($page > 1) {
                $content .= '<a class="btn btn-secondary" href="/plugin/verkaufsboerse/verwaltung?seite=' . ($page - 1) . '">&laquo; Zurück</a> ';
            }
            $content .= 'Seite ' . $page . ' von ' . $pageCount . ' (' . $totalListings . ' Inserate)';
            if ($page < $pageCount) {
                $content .= ' <a class="btn btn-secondary" href="/plugin/verkaufsboerse/verwaltung?seite=' . ($page + 1) . '">Weiter &raquo;</a>';
            }
            $content .= '</p>';
        }

        $content .= '<p><a href="/admin" class="btn btn-secondary">Zurück zum Dashboard</a></p>';
        $content .= '</div>';

        PluginPage::render('Verkaufsbörse verwalten', $content);
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

        // Zurück auf die Listenseite, von der gelöscht wurde (#74); index()
        // klemmt einen inzwischen zu großen Wert selbst auf die letzte Seite.
        $seite = (int) ($_POST['seite'] ?? 1);
        header('Location: /plugin/verkaufsboerse/verwaltung' . ($seite > 1 ? '?seite=' . $seite : ''));
        exit;
    }

    /**
     * Serverseitige Pferdesuche für die Datalist (#74, Muster
     * Framework-Katalog): JSON-Liste {id, label} über eine Teilstring-Suche
     * im Namen, höchstens SEARCH_LIMIT Treffer. Läuft über denselben
     * Konstruktor-Schutz (verkaufsboerse.manage) wie die Verwaltungsseite.
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
