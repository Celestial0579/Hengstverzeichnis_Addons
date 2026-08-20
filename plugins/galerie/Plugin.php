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
use App\Service\AuditLogger;
use PDO;

class Plugin {

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
                    $html .= '<img src="' . $esc(self::bildUrl((int) $row['id'])) . '" alt="" loading="lazy" decoding="async"'
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
    /**
     * Ablageverzeichnis für hochgeladene Bilder: bewusst AUSSERHALB des
     * Webroots (public/), damit die Dateien nie direkt per URL abrufbar sind,
     * sondern ausschließlich über die zugriffsgeschützte Route `/bild`.
     *
     * Vorher lagen sie unter public/uploads/plugin_galerie/ und der rohe Pfad
     * stand im <img src>. Damit war der Dateiname öffentlich bekannt, und die
     * Datei blieb nach einer Depublikation des Pferdes weiter abrufbar - die
     * Depublikation war für Galeriebilder also wirkungslos. Dasselbe Muster
     * wie beim Addon `gesundheitstests`, das seine Dokumente schon immer so
     * ablegt.
     */
    public static function storageDir(): string {
        return dirname(__DIR__, 2) . '/storage/plugin_galerie';
    }

    /**
     * Adresse eines Mediums für die Ausgabe im HTML. Die Datei-ID genügt: Der
     * Dateiname erscheint nirgends mehr in einer öffentlichen Antwort.
     */
    public static function bildUrl(int $mediaId): string {
        return '/plugin/galerie/bild?id=' . $mediaId;
    }

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

        $this->migriereBestandsdateien();
    }

    /**
     * Holt Bestandsbilder aus dem Webroot in die geschützte Ablage.
     *
     * `install()` läuft bei der Aktivierung UND nach jedem Addon-Update, ist
     * also die richtige Stelle - aber genau deshalb muss der Schritt beliebig
     * oft wiederholbar sein, ohne Schaden anzurichten:
     *
     * - Eine Datei, die im alten Verzeichnis nicht (mehr) liegt, wird
     *   übersprungen; beim zweiten Lauf ist das der Normalfall.
     * - Liegt die Datei am Ziel bereits, wird die Quelle nur noch entfernt.
     *   Die Zieldatei wird nie überschrieben - der Dateiname trägt einen
     *   Zufallsanteil, eine Namensgleichheit bei verschiedenem Inhalt ist
     *   damit praktisch ausgeschlossen, und im Zweifel gilt das Ziel.
     * - Erst wenn die Datei nachweislich am Ziel liegt, wird `file_path` in
     *   der Datenbank auf den bloßen Dateinamen umgestellt. Bricht der Lauf
     *   dazwischen ab, findet ihn der nächste im selben Zustand wieder.
     *
     * Der Wert in `file_path` unterscheidet die beiden Zustände von selbst:
     * alt = '/uploads/plugin_galerie/<datei>', neu = '<datei>'.
     */
    private function migriereBestandsdateien(): void {
        $db = Database::getInstance();
        $alteAblage = dirname(__DIR__, 2) . '/public/uploads/plugin_galerie';
        $zielAblage = self::storageDir();

        $rows = $db->query(
            "SELECT id, file_path FROM `plugin_galerie_media`
             WHERE type = 'image' AND file_path LIKE '/uploads/plugin_galerie/%'"
        )->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) {
            return;
        }

        if (!is_dir($zielAblage) && !mkdir($zielAblage, 0750, true) && !is_dir($zielAblage)) {
            error_log('galerie: Ablage ' . $zielAblage . ' liess sich nicht anlegen - Bestandsbilder bleiben liegen.');
            return;
        }

        $update = $db->prepare('UPDATE `plugin_galerie_media` SET file_path = :pfad WHERE id = :id');

        foreach ($rows as $row) {
            $name = basename((string) $row['file_path']);
            if ($name === '' || $name === '.' || $name === '..') {
                continue;
            }

            $quelle = $alteAblage . '/' . $name;
            $ziel = $zielAblage . '/' . $name;

            if (is_file($ziel)) {
                // Schon umgezogen (abgebrochener Vorlauf): nur die Quelle weg.
                if (is_file($quelle)) {
                    @unlink($quelle);
                }
            } elseif (is_file($quelle)) {
                if (!@rename($quelle, $ziel)) {
                    error_log('galerie: ' . $quelle . ' liess sich nicht nach ' . $ziel . ' verschieben.');
                    continue;
                }
                @chmod($ziel, 0640);
            } else {
                // Datei fehlt in beiden Ablagen - der Datensatz zeigt ins
                // Leere. Der Pfad wird trotzdem umgestellt, damit dieser
                // Datensatz nicht bei jedem Update erneut geprüft wird; die
                // Ausgabe behandelt fehlende Dateien ohnehin als "kein Bild".
                error_log('galerie: Datei ' . $name . ' zu Medium ' . (int) $row['id'] . ' fehlt in beiden Ablagen.');
            }

            $update->execute(['pfad' => $name, 'id' => (int) $row['id']]);
        }
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

        // Zurückgegeben wird eine NEU GEBAUTE URL aus genau den Teilen, die
        // oben geprüft wurden - nicht die Eingabe.
        //
        // Grund ist eine ganze Fehlerklasse, nicht ein einzelner Trick: Die
        // Prüfung macht PHPs parse_url(), angezeigt wird die Zeichenkette
        // später in einem <iframe src>, also im Parser des Browsers. Solange
        // die Eingabe unverändert durchgereicht wird, muss man sich darauf
        // verlassen, dass beide Parser jede Eingabe gleich lesen - und genau
        // solche Abweichungen sind der Stoff, aus dem Allowlist-Umgehungen
        // gemacht sind (Benutzerinfo vor dem @, Rückwärtsschrägstriche,
        // Steuerzeichen, doppelte Fragmente).
        //
        // Wird die URL neu zusammengesetzt, ist diese Frage gegenstandslos:
        // Was der Browser sieht, ist per Konstruktion das, was hier geprüft
        // wurde. Benutzerinfo und Fragment fallen dabei ganz weg - beide
        // haben in einer eingebetteten Video-URL nichts zu suchen.
        $rebuilt = 'https://' . $host;
        if (isset($parts['port'])) {
            $rebuilt .= ':' . (int) $parts['port'];
        }
        $rebuilt .= $parts['path'] ?? '/';
        if (isset($parts['query']) && $parts['query'] !== '') {
            $rebuilt .= '?' . $parts['query'];
        }

        // Steuerzeichen können in Pfad/Query stehen, ohne dass parse_url
        // stolpert - im Attribut beenden sie unter Umständen den Wert.
        if (preg_match('/[\x00-\x1F\x7F"\'<>\\\\]/', $rebuilt) === 1) {
            return null;
        }

        return $rebuilt;
    }

    /**
     * Filter-Beispiel: Galerie-Grid mit schlanker Lightbox (reines
     * Inline-CSS/JS, keine externe Bibliothek) auf der öffentlichen
     * Detailseite. Zeigt nichts an, wenn keine Medien erfasst sind.
     */
    public function addDetailSection(array $sections, array $horse, array $horsePersons, ?array $pedigree): array {
        $stmt = Database::getInstance()->prepare(
            'SELECT id, type, file_path, video_url, caption
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
                $src = htmlspecialchars(self::bildUrl((int) $item['id']), ENT_QUOTES, 'UTF-8');
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
            // Die addoneigene Pferdesuche (/suche) ist mit Addons#125
            // entfallen: Sieben Addons brachten dieselbe Route und denselben
            // JS-Block mit, und jede Kopie war eine eigene Stelle, an der
            // Deckelung, Rechteprüfung und das Maskieren der LIKE-Platzhalter
            // richtig sein mussten. Der Kern liefert das seit Framework#341
            // unter /admin/horses/search.
            ['method' => 'POST', 'path' => '/verwaltung/store', 'callback' => [VerwaltungController::class, 'store']],
            ['method' => 'POST', 'path' => '/verwaltung/delete', 'callback' => [VerwaltungController::class, 'delete']],
            // Zugriffsgeschützte Bildauslieferung. Die Bilder liegen außerhalb
            // des Webroots und sind ausschließlich hierüber erreichbar -
            // dieselben Sichtbarkeitsregeln wie beim Kernfoto über
            // /media/horse-image.
            ['method' => 'GET', 'path' => '/bild', 'callback' => [BildController::class, 'serve']],
        ];
    }
}

/**
 * Admin-Verwaltung der Galerie-Medien. Zugriffsschutz über die selbst
 * registrierte Berechtigung "galerie.manage", analog zum
 * zuchtschau-ergebnisse-Muster.
 */
class VerwaltungController extends BaseController {

    /**
     * Seitennummer aus der Anfrage - validiert, nicht umgedeutet.
     *
     * filter_var mit FILTER_VALIDATE_INT lehnt ab, was keine Zahl IST; ein
     * blosser (int)-Cast machte aus "abc" eine 0 und aus "3x" eine 3. Der
     * Cast ist ausserdem fuer eine statische Analyse keine erkennbare
     * Bereinigung - die Seitennummer floss deshalb als "Nutzerdaten" bis in
     * den HTML-Aufbau und liess dort eine Injection-Regel anschlagen.
     */
    private static function seitenNummer(): int {
        $wert = filter_var(
            $_GET['seite'] ?? 1,
            FILTER_VALIDATE_INT,
            ['options' => ['default' => 1, 'min_range' => 1]]
        );
        return is_int($wert) ? $wert : 1;
    }


    // Der Treffer-Deckel der Pferdesuche steht seit Addons#125 im Kern
    // (HorseSearchController::MAX_TREFFER) - die addoneigene Konstante ist
    // damit entfallen, zusammen mit der Route, die sie deckelte.

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
        $page = min($pageCount, self::seitenNummer());

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

        // Pferde-Auswahl über das gemeinsame Suchfeld des Kerns (Addons#125):
        // `hv-pferdesuche` verdrahtet /js/horse-search.js mit dem Endpunkt
        // /admin/horses/search und füllt das über `data-ziel` benannte
        // <select>. Der frühere addoneigene JS-Block samt /suche-Route ist
        // damit weg - inklusive seines Wettlaufs zwischen zwei schnell
        // aufeinanderfolgenden Anfragen, den er nicht behandelte.
        //
        // Das Textfeld behält seinen Namen `horse_q`: Ohne JavaScript bleibt
        // die Auswahlliste leer, und dann löst store() den getippten Text
        // über resolveHorseId() auf. Ein <select> allein wäre ohne JS ein
        // Formular, das sich nicht absenden lässt.
        $content .= '<div class="form-group"><label for="horse_q">Pferd</label>'
            . '<input type="text" name="horse_q" id="horse_q" class="form-control hv-pferdesuche"'
            . ' data-ziel="horse_id" autocomplete="off"'
            . ' placeholder="Namen eintippen und Vorschlag auswählen …" required>'
            . '<select name="horse_id" id="horse_id" class="form-control" style="margin-top:0.4rem;">'
            . '<option value="">– bitte oben suchen –</option></select>'
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

        // Progressive Enhancement der Pferdesuche: Das Skript des Kerns
        // (Framework#341) verdrahtet jedes Feld mit der Klasse
        // `hv-pferdesuche`. Ohne fetch()/JS greift der No-JS-Fallback in
        // store().
        $content .= '<script src="/js/horse-search.js"></script>';

        $content .= '<h2>Erfasste Medien</h2>';
        $content .= '<table><thead><tr><th>Pferd</th><th>Typ</th><th>Vorschau/Link</th><th>Bildunterschrift</th><th>Sortierung</th><th></th></tr></thead><tbody>';
        foreach ($media as $row) {
            $content .= '<tr>';
            $content .= '<td>' . htmlspecialchars((string) $row['horse_name'], ENT_QUOTES, 'UTF-8') . '</td>';
            $content .= '<td>' . ($row['type'] === 'image' ? 'Foto' : 'Video') . '</td>';
            $content .= '<td>';
            if ($row['type'] === 'image' && !empty($row['file_path'])) {
                $content .= '<img class="galerie-thumb" src="' . htmlspecialchars(Plugin::bildUrl((int) $row['id']), ENT_QUOTES, 'UTF-8') . '" alt="">';
            } elseif (!empty($row['video_url'])) {
                $url = htmlspecialchars((string) $row['video_url'], ENT_QUOTES, 'UTF-8');
                $content .= '<a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . $url . '</a>';
            }
            $content .= '</td>';
            $content .= '<td>' . htmlspecialchars((string) ($row['caption'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
            $content .= '<td>' . (int) $row['sort_order'] . '</td>';
            $content .= '<td><form method="POST" action="/plugin/galerie/verwaltung/delete" style="margin:0;" onsubmit="return confirm(\'Medium wirklich entfernen?\');">'
                . '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') . '">'
                . '<input type="hidden" name="seite" value="' . (int) $page . '">'
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
            $content .= 'Seite ' . (int) $page . ' von ' . (int) $pageCount . ' (' . (int) $totalMedia . ' Medien)';
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

        // ID aus dem Auswahlfeld, das das gemeinsame Suchfeld des Kerns füllt
        // (Addons#125), sonst No-JS-Fallback: den getippten Text des
        // Suchfelds serverseitig auflösen (#74). In
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

                // Protokoll (#134): Kategorie = Addon-Slug. Der Eintrag nennt
                // Datensatz und Bezug (welches Medium, welches Pferd) sowie
                // die Medienquelle - bei einem Bild den ABGELEGTEN Dateinamen,
                // denn genau der wird beim Löschen wieder entfernt.
                // Die Bildunterschrift bleibt draußen: freier Text, der
                // Personen benennen kann, und für den Nachweis der Handlung
                // ohne Wert.
                $mediumId = (int) $db->lastInsertId();
                AuditLogger::log(
                    'Galerie: Medium hinzugefügt',
                    'galerie',
                    "Medium #{$mediumId}, Pferd #{$horseId} (" . self::pferdeName($db, $horseId) . '), '
                        . ($type === 'image'
                            ? 'Bild, Datei: ' . $imagePath
                            : 'Video, Verweis: ' . $safeVideoUrl)
                );
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
            // Pferd mitgelesen (#134): Nach dem DELETE ist der Bezug nicht mehr
            // zu ermitteln - "Medium gelöscht" ohne Angabe, welches und zu
            // welchem Pferd, hilft im Protokoll niemandem. LEFT JOIN, damit ein
            // fehlendes Pferd die Zeile nicht verschwinden lässt.
            $stmt = $db->prepare(
                'SELECT m.type, m.file_path, m.horse_id, h.name AS horse_name
                 FROM `plugin_galerie_media` m
                 LEFT JOIN horses h ON h.id = m.horse_id
                 WHERE m.id = :id'
            );
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                $dateiVermerk = 'ohne hochgeladene Datei';
                if ($row['type'] === 'image' && !empty($row['file_path'])) {
                    // file_path ist ein selbst generierter Dateiname (siehe
                    // handleImageUpload) - basename() verhindert zusätzlich
                    // jedes Traversal. Bestandsdatensätze, die noch den alten
                    // öffentlichen Pfad tragen, reduziert basename() auf
                    // denselben Namen.
                    $dateiName = basename((string) $row['file_path']);
                    $path = Plugin::storageDir() . '/' . $dateiName;
                    if (is_file($path)) {
                        @unlink($path);
                        // Nach dem unlink geprüft: Ein fehlgeschlagenes
                        // Entfernen darf nicht als "Datei entfernt"
                        // protokolliert werden - dann bliebe genau die Datei
                        // liegen, die das Protokoll als gelöscht ausweist.
                        $dateiVermerk = is_file($path)
                            ? "Datei {$dateiName} konnte NICHT entfernt werden"
                            : "Datei {$dateiName} entfernt";
                    } else {
                        $dateiVermerk = "Datei {$dateiName} war bereits nicht mehr vorhanden";
                    }
                }

                $deleteStmt = $db->prepare('DELETE FROM `plugin_galerie_media` WHERE id = :id');
                $deleteStmt->execute(['id' => $id]);

                // Protokoll (#134): das Löschen eines Galeriebildes samt Datei
                // war bisher spurlos. Ohne Bildunterschrift - freier Text.
                AuditLogger::log(
                    'Galerie: Medium gelöscht',
                    'galerie',
                    "Medium #{$id}, Pferd #" . (int) $row['horse_id']
                        . ' (' . (string) ($row['horse_name'] ?? 'unbekannt') . '), '
                        . ($row['type'] === 'image' ? 'Bild' : 'Video') . ', ' . $dateiVermerk
                );
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
     * No-JS-Fallback: löst den getippten Text des Suchfelds serverseitig zu
     * einer Pferde-ID auf - nur bei eindeutigem Treffer, sonst null.
     */
    private function resolveHorseId(PDO $db, string $q): ?int {
        // 1) Eindeutigkeits-Suffix "… [#123]". Die Vorschlagsliste des Kerns
        //    erzeugt es seit Addons#125 nicht mehr (sie hängt UELN und
        //    Jahrgang an); als ausdrückliche Eingabe bleibt es der einzige
        //    Weg, ohne JavaScript ein bestimmtes von zwei namensgleichen
        //    Pferden zu benennen - deshalb wird es weiterhin akzeptiert.
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
     * Name eines Pferdes für den Protokolleintrag (#134). Eine reine ID ist
     * im Protokoll wertlos, sobald das Pferd selbst gelöscht ist - der Name
     * bleibt lesbar. Fällt auf "unbekannt" zurück statt zu scheitern: Ein
     * Protokolleintrag darf nie die Ursache dafür sein, dass die eigentliche
     * Handlung abbricht.
     */
    private static function pferdeName(PDO $db, int $horseId): string {
        $stmt = $db->prepare('SELECT name FROM horses WHERE id = ?');
        $stmt->execute([$horseId]);
        $name = $stmt->fetchColumn();

        return $name !== false ? (string) $name : 'unbekannt';
    }

    /**
     * Gleiches Upload-/Validierungsmuster wie HorseController::
     * handleImageUpload() im Kern (echte MIME-Prüfung per finfo, max. 5 MB,
     * Zufallsname), nur mit eigenem Zielverzeichnis - und dieses liegt
     * AUSSERHALB des Webroots (Plugin::storageDir()).
     *
     * Gespeichert wird deshalb nur noch der bloße Dateiname, nicht mehr ein
     * öffentlicher Pfad: Die Adresse entsteht erst bei der Ausgabe aus der
     * Medien-ID (Plugin::bildUrl()), der Dateiname erscheint nirgends.
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

        $uploadDir = Plugin::storageDir();
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0750, true) && !is_dir($uploadDir)) {
            return null;
        }

        $filename = 'galerie_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $allowedMimeTypes[$mime];
        if (!move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $filename)) {
            return null;
        }
        @chmod($uploadDir . '/' . $filename, 0640);

        return $filename;
    }
}

/**
 * Zugriffsgeschützte Auslieferung der Galeriebilder.
 *
 * Vorher lagen die Bilder unter public/uploads/plugin_galerie/ und der rohe
 * Pfad stand im `<img src>`. Damit gab es für sie überhaupt keine Prüfung: Der
 * Webserver lieferte die Datei aus, der Dateiname war öffentlich bekannt, und
 * nach einer Depublikation des Pferdes - etwa nach einem Widerspruch nach
 * Art. 21 DSGVO - blieb das Foto unter der bekannten Adresse abrufbar.
 *
 * Die Regeln hier sind bewusst dieselben wie im Kern für das Hauptfoto
 * (App\Controllers\MediaController::horseImage()); eine eigene, schlankere
 * Fassung wäre eine zweite Fassung derselben Regel:
 *
 * - Eine Sitzung zählt nur, wenn sie checkAuth() besteht - nicht, wenn
 *   irgendwann einmal jemand angemeldet war. Sonst gölte hier keine der
 *   Prüfungen des übrigen Backends (gelöschtes Konto, session_version nach
 *   einem Passwortwechsel, User-Agent, Inaktivität).
 * - `horses.view` ist Pflicht, für Gäste wie für Angemeldete.
 * - Ein unveröffentlichtes Pferd ist für Gäste nicht vorhanden - sein Foto
 *   also auch nicht.
 *
 * Unbekannte, gelöschte und nicht zugängliche IDs liefern eine identische 404,
 * damit die Route kein Existenz-Orakel wird.
 */
class BildController extends BaseController {

    /** Positivliste statt finfo-Raten - dieselbe wie beim Upload. */
    private const TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    public function serve(): void {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($id <= 0) {
            $this->renderNotFound('Bild nicht gefunden.');
        }

        // Datenbankfehler enden als 404, nicht als 500: "kein Bild" ist die
        // richtige Antwort auf eine Bildanfrage. Protokolliert wird trotzdem.
        try {
            $stmt = Database::getInstance()->prepare(
                "SELECT m.file_path, h.is_published
                 FROM `plugin_galerie_media` m
                 JOIN horses h ON h.id = m.horse_id AND h.deleted_at IS NULL
                 WHERE m.id = ? AND m.type = 'image'"
            );
            $stmt->execute([$id]);
            $medium = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('galerie: Bildabfrage fehlgeschlagen: ' . $e->getMessage());
            $this->renderNotFound('Bild nicht gefunden.');
            return;
        }

        if (!$medium || empty($medium['file_path'])) {
            $this->renderNotFound('Bild nicht gefunden.');
        }

        $angemeldet = false;
        if (!empty($_SESSION['user_id'])) {
            $this->checkAuth();
            $angemeldet = true;
        }

        if (!$this->hasPermission('horses', 'view')) {
            $this->renderNotFound('Bild nicht gefunden.');
        }
        if (empty($medium['is_published']) && !$angemeldet) {
            $this->renderNotFound('Bild nicht gefunden.');
        }

        // Sitzungssperre freigeben, sobald sie nicht mehr gebraucht wird
        // (#142). Ab hier wird nur noch gelesen - checkAuth() ist durch, die
        // Rechteprüfung auch.
        //
        // Ohne das reihen sich die Bildanfragen HINTEREINANDER auf: PHPs
        // Standard-Sitzungsspeicher hält die Sitzungsdatei bis zum Ende des
        // Requests exklusiv gesperrt, und config/config.php startet für jeden
        // Besucher eine Sitzung. Eine Verwaltungsseite mit 50 Vorschaubildern
        // löst 50 Anfragen aus, die dann seriell statt parallel laufen - bei
        // 60 ms je Anfrage rund 3 s, in denen der Browser blockiert nachlädt.
        // Der Kern gibt sie aus genau diesem Grund frei
        // (App\Controllers\MediaController); hier fehlte es.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $pfad = $this->dateiPfad((string) $medium['file_path']);
        if ($pfad === null) {
            $this->renderNotFound('Bild nicht gefunden.');
        }

        $endung = strtolower(pathinfo($pfad, PATHINFO_EXTENSION));
        $mtime = (int) (@filemtime($pfad) ?: 0);
        $groesse = (int) (@filesize($pfad) ?: 0);
        $etag = '"' . md5($pfad . '|' . $mtime . '|' . $groesse) . '"';

        header('Content-Type: ' . self::TYPES[$endung]);
        header('X-Content-Type-Options: nosniff');
        // Wie im Kern: hält fremde Seiten davon ab, die Bilder einzubetten.
        header('Cross-Origin-Resource-Policy: same-origin');
        // Ein zugriffsabhängiges Bild darf nie in einem gemeinsamen
        // Zwischenspeicher landen (Framework#315): Sonst liegt das Foto eines
        // unveröffentlichten Pferdes dort und geht von da an jeden Gast.
        if (empty($medium['is_published'])) {
            header('Cache-Control: private, no-store');
        } else {
            header('Cache-Control: public, max-age=31536000, immutable');
        }
        header('ETag: ' . $etag);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');

        // Bedingte Anfragen (#142). Sie sparen die Übertragung auch dort, wo
        // `no-store` gilt: Der Browser darf die Datei zwar nicht ablegen, er
        // fragt aber trotzdem mit If-None-Match nach - und bekommt dann 304
        // statt des vollen Bildes. Für unveröffentlichte Pferde ist das der
        // einzige Weg, das Neuladen einer Verwaltungsseite billig zu machen.
        $ifNoneMatch = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
        $ifModifiedSince = strtotime((string) ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '')) ?: 0;
        if ($ifNoneMatch === $etag || ($ifModifiedSince > 0 && $mtime > 0 && $ifModifiedSince >= $mtime)) {
            http_response_code(304);
            exit;
        }

        header('Content-Length: ' . (string) $groesse);

        // Ausgabepuffer leeren, sonst hält PHP die gesamte Datei im Speicher,
        // bevor das erste Byte den Server verlässt.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        readfile($pfad);
        exit;
    }

    /**
     * Bildet den gespeicherten Wert auf eine Datei in der geschützten Ablage
     * ab. Gibt null zurück, sobald irgendetwas nicht passt - insbesondere,
     * wenn der aufgelöste Pfad das Verzeichnis verlässt.
     *
     * `basename()` deckt zugleich die Bestandsdatensätze ab, die noch den
     * alten öffentlichen Pfad tragen, falls die Migration in install() eine
     * Zeile nicht umstellen konnte.
     */
    private function dateiPfad(string $gespeichert): ?string {
        $basis = realpath(Plugin::storageDir());
        if ($basis === false) {
            return null;
        }

        $name = basename($gespeichert);
        if ($name === '' || $name === '.' || $name === '..') {
            return null;
        }

        if (!isset(self::TYPES[strtolower(pathinfo($name, PATHINFO_EXTENSION))])) {
            return null;
        }

        // realpath löst Symlinks auf: Ein Link in der Ablage dürfte sonst auf
        // jede Datei des Systems zeigen.
        $voll = realpath($basis . '/' . $name);
        if ($voll === false || !is_file($voll)) {
            return null;
        }
        if (!str_starts_with($voll, $basis . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $voll;
    }
}
