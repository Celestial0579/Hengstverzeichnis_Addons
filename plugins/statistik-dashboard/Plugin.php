<?php
// statistik-dashboard/Plugin.php
//
// Addon für Hengstverzeichnis_Framework: eine Statistik-Seite für den
// Admin-Bereich mit zwei Abschnitten - "Bestand" (Pferde nach Status,
// Verteilung nach Deckstation, Wachstum über Zeit, meistgenutzte Blutlinien)
// und "Aufrufe" (Rangliste der meistgesehenen Pferde, gezählt auf der
// öffentlichen Detailseite).
//
// Zusammenführung (Addons#127): Bis v0.7 waren das zwei Addons -
// `statistik-dashboard` (Bestand) und `besucherstatistik` (Aufrufe). Beide
// beantworteten dieselbe Frage ("wie steht es um den Bestand"), lagen sogar
// unter demselben Pfadstück `/plugin/<slug>/statistik`, und wer eine Zahl
// suchte, musste wissen, in welchem der beiden sie wohnt. Seit #127 ist es
// ein Addon mit einer Seite, einer Kachel, einer Berechtigung.
//
// Warum der Slug `statistik-dashboard` gewinnt und nicht ein neuer Slug
// `statistik`: Ein neuer Slug wäre für JEDE Bestandsinstallation ein Umzug -
// neues Verzeichnis, neuer Store-Eintrag, neue Aktivierung, neu zu vergebende
// Berechtigungen, und das für beide alten Addons. So bleibt eine der beiden
// Installationen vollständig unangetastet (Aktivierung, Route, zugewiesene
// Rechte), und nur `besucherstatistik` muss deaktiviert und entfernt werden.
// Der bessere Name war das nicht wert; die Seite heißt in der Oberfläche
// ohnehin schlicht "Statistik".
//
// Was mit den Daten des verschwindenden Addons passiert, steht bei
// uebernahmeAusBesucherstatistik().
//
// Dieses Addon ist zugleich das Beispiel-Addon des Repos - eine Rolle, die es
// mit #127 ausdrücklich von `besucherstatistik` übernimmt (siehe README):
// es führt alle Erweiterungspunkte des Plugin-Systems vor - Filter
// (horse.detail_sections, admin.dashboard_tiles), Action (horse.after_save),
// eigene Tabellen, eigene Route und eigenes Berechtigungsmodul.
//
// Installation (lokal im Framework-Repo):
//   cp -r statistik-dashboard plugins/statistik-dashboard
// Danach unter Admin -> Plugins verwalten (/admin/plugins) aktivieren und
// der gewünschten Gruppe unter /admin/groups die Berechtigung
// "Statistik -> Statistik einsehen" zuweisen.
//
// Siehe docs/plugin-development.md im Framework-Repo für die vollständige
// Hook-/Routen-/Berechtigungs-Referenz.

namespace Plugin\StatistikDashboard;

use App\Controllers\BaseController;
use App\Database;
use App\Plugin\HookManager;
use App\Plugin\PluginPage;
use PDO;

class Plugin {

    /** Eigene Zähltabelle des zusammengeführten Addons (#127). */
    public const TABELLE_AUFRUFE = 'plugin_statistik_dashboard_views';

    /** Marker-/Konfigurationstabelle des Addons. */
    public const TABELLE_META = 'plugin_statistik_dashboard_meta';

    /** Slug des aufgegangenen Addons (#127). */
    public const SLUG_ALT = 'besucherstatistik';

    /** Zähltabelle des aufgegangenen Addons `besucherstatistik` (#127). */
    public const TABELLE_ALT = 'plugin_besucherstatistik_views';

    /** Schlüssel des Übernahme-Markers, siehe uebernahmeAusBesucherstatistik(). */
    public const MARKER_UEBERNAHME = 'uebernahme.besucherstatistik';

    public function register(HookManager $hooks): void {
        // Kein ensureTable() hier: Die Tabellen legt install() an, das der
        // PluginManager bei Aktivierung und nach jedem Addon-Update genau
        // einmal aufruft (Framework #75). register() läuft bei JEDEM Request
        // und darf deshalb kein DDL und keine Migration ausführen.
        $hooks->addFilter('horse.detail_sections', [$this, 'addDetailSection']);
        $hooks->addFilter('admin.dashboard_tiles', [$this, 'addDashboardTile']);
        $hooks->addAction('horse.after_save', [$this, 'onHorseSaved']);
    }

    /**
     * Framework-Hook (#75): Der PluginManager ruft install() bei der
     * Aktivierung und nach einem Addon-Update auf - der Hook garantiert
     * "mindestens einmal nach Installation/Update", NICHT "genau einmal".
     * Alles hier muss deshalb beliebig oft wiederholbar sein.
     */
    public function install(): void {
        $db = Database::getInstance();

        $db->exec(
            'CREATE TABLE IF NOT EXISTS `' . self::TABELLE_AUFRUFE . '` (
                `horse_id` INT NOT NULL PRIMARY KEY,
                `views` INT UNSIGNED NOT NULL DEFAULT 0,
                `last_viewed_at` DATETIME NULL DEFAULT NULL,
                FOREIGN KEY (`horse_id`) REFERENCES `horses`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        // Eigene Marker-Tabelle statt der Kern-Tabelle `settings`: deren
        // Schlüsselspalte ist VARCHAR(50), und die Systemeinstellungen sind
        // eine redaktionell gepflegte Oberfläche, in die ein Addon keine
        // Fremdschlüssel streuen sollte (gleiche Begründung wie bei
        // `plugin_kontaktanfrage_config`).
        $db->exec(
            'CREATE TABLE IF NOT EXISTS `' . self::TABELLE_META . '` (
                `meta_key` VARCHAR(64) NOT NULL PRIMARY KEY,
                `meta_value` TEXT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $this->uebernahmeAusBesucherstatistik($db);
    }

    /**
     * Übernahme der Bestandsdaten des aufgegangenen Addons
     * `besucherstatistik` (#127) - Aufrufzahlen und Rechtezuweisungen.
     *
     * ### Warum ein Marker und nicht bloß "idempotent geschrieben"
     *
     * install() läuft bei JEDER Aktivierung erneut. Ein Deaktivieren und
     * wieder Aktivieren über /admin/plugins - für Admins ein harmloser
     * Handgriff - ruft die Übernahme also ein zweites Mal auf. Beide
     * Schritte hier wären dabei falsch:
     *
     * - Die Aufrufzahlen werden **addiert** (`neu.views = neu.views +
     *   alt.views`), weil ein Pferd im alten wie im neuen Zähler stehen kann
     *   und die Summe die richtige Antwort ist. Genau deshalb würde ein
     *   zweiter Lauf die Zahlen verdoppeln - lautlos, und nicht mehr
     *   rekonstruierbar, weil niemand weiß, welcher Anteil doppelt ist.
     *   Nachgewiesen in StatistikDashboardPluginTest: ohne den Marker steht
     *   dort 2003 statt 1003.
     * - Die Rechtezuweisungen zu übertragen ist für sich genommen zwar
     *   idempotent (INSERT IGNORE auf einen Primärschlüssel), aber ein
     *   zweiter Lauf würde ein Recht wiederherstellen, das ein Admin
     *   zwischenzeitlich bewusst entzogen hat. Ein Rechteentzug, den eine
     *   Reaktivierung rückgängig macht, ist eine Sicherheitslücke.
     *
     * Der Marker in `plugin_statistik_dashboard_meta` (Schlüssel
     * `uebernahme.besucherstatistik`) hält deshalb fest,
     * dass die Übernahme gelaufen ist. Er wird in derselben Transaktion
     * geschrieben wie die Zählerübernahme: Bricht der Lauf ab, gilt weder
     * das eine noch das andere, und der nächste findet einen sauberen
     * Ausgangszustand vor.
     *
     * ### Was mit der alten Tabelle passiert
     *
     * Sie bleibt liegen. `plugin_besucherstatistik_views` gehört einem
     * fremden Addon; sie zu löschen wäre erstens nicht Sache dieses Addons
     * und nähme zweitens die einzige Rückfallebene, falls an der Übernahme
     * etwas nicht stimmt.
     *
     * Den geordneten Weg für Daten deinstallierter Addons gibt es seit Kern
     * 0.8 (Framework#338: das Register `owns` in der plugin.json, ausgewertet
     * beim Deinstallieren). Er gehört ins Manifest von `besucherstatistik`,
     * nicht hierher - das Addon räumt seine eigenen Daten weg, wenn der
     * Betreiber es deinstalliert. Für dieses Addon steht das Register noch
     * aus; es ist eine Aufgabe über alle 20 Addons hinweg und kein Nebenzug
     * von Addons#139.
     */
    private function uebernahmeAusBesucherstatistik(PDO $db): void {
        if ($this->marker($db, self::MARKER_UEBERNAHME) !== null) {
            return;
        }

        // Ohne Quelltabelle gab es dieses Addon auf der Instanz nie - dann
        // wird auch kein Marker gesetzt: "nichts vorgefunden" ist keine
        // gelaufene Übernahme. Sonst bliebe eine Instanz, auf der die alte
        // Tabelle erst später auftaucht (Restore einer Sicherung), für immer
        // ausgeschlossen.
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM `information_schema`.`TABLES`
             WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = :name'
        );
        $stmt->execute(['name' => self::TABELLE_ALT]);
        if ((int) $stmt->fetchColumn() === 0) {
            return;
        }

        // Rechtezuweisungen zuerst, außerhalb der Transaktion: Sie sind für
        // sich idempotent, und wenn der Zählerteil scheitert, ist ein zu
        // früh übertragenes Recht der harmlosere Zustand als ein Admin, der
        // nach dem Update vor einer leeren Statistik steht.
        //
        // `group_permissions` ist eine Kern-Tabelle; geschrieben wird
        // ausschließlich die eigene Modulzeile (module = 'statistik-dashboard'),
        // abgeleitet aus den vorhandenen Zeilen des alten Moduls. Fremde
        // Module werden nicht angefasst.
        $db->exec(
            "INSERT IGNORE INTO `group_permissions` (`group_id`, `module`, `action`)
             SELECT `group_id`, 'statistik-dashboard', 'view'
             FROM `group_permissions`
             WHERE `module` = 'besucherstatistik' AND `action` = 'view'"
        );

        $db->beginTransaction();
        try {
            // Zwei Schritte statt eines INSERT ... ON DUPLICATE KEY UPDATE:
            // Dort müsste der zu übernehmende Wert über VALUES() angesprochen
            // werden, das MySQL seit 8.0.20 zugunsten von Zeilen-Aliassen
            // abkündigt - hier läuft beides ohne Sonderform.
            //
            // 1. Schon vorhandene Pferde: addieren, nicht ersetzen. Ein Pferd
            //    kann in beiden Zählern stehen (etwa weil das alte Addon nach
            //    dem Update noch kurz mitlief), und die Summe ist die richtige
            //    Zahl. Genau diese Addition wäre bei einem zweiten Lauf die
            //    Verdopplung, die der Marker verhindert.
            //    `last_viewed_at` gewinnt der jüngere Wert; GREATEST liefert
            //    NULL, sobald ein Wert NULL ist, daher COALESCE außen herum.
            $db->exec(
                'UPDATE `' . self::TABELLE_AUFRUFE . '` neu
                 JOIN `' . self::TABELLE_ALT . '` alt ON alt.`horse_id` = neu.`horse_id`
                 SET neu.`views` = neu.`views` + alt.`views`,
                     neu.`last_viewed_at` = COALESCE(
                         GREATEST(alt.`last_viewed_at`, neu.`last_viewed_at`),
                         alt.`last_viewed_at`,
                         neu.`last_viewed_at`
                     )'
            );

            // 2. Noch unbekannte Pferde: unverändert übernehmen.
            $db->exec(
                'INSERT INTO `' . self::TABELLE_AUFRUFE . '` (`horse_id`, `views`, `last_viewed_at`)
                 SELECT alt.`horse_id`, alt.`views`, alt.`last_viewed_at`
                 FROM `' . self::TABELLE_ALT . '` alt
                 LEFT JOIN `' . self::TABELLE_AUFRUFE . '` neu ON neu.`horse_id` = alt.`horse_id`
                 WHERE neu.`horse_id` IS NULL'
            );

            $anzahl = (int) $db->query('SELECT COUNT(*) FROM `' . self::TABELLE_ALT . '`')->fetchColumn();

            $setzen = $db->prepare(
                'INSERT INTO `' . self::TABELLE_META . '` (`meta_key`, `meta_value`)
                 VALUES (:k, :v)'
            );
            $setzen->execute([
                'k' => self::MARKER_UEBERNAHME,
                'v' => date('c') . '; übernommene Zeilen: ' . $anzahl,
            ]);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /** Liest einen Marker; null, wenn er nicht gesetzt ist. */
    private function marker(PDO $db, string $schluessel): ?string {
        $stmt = $db->prepare(
            'SELECT `meta_value` FROM `' . self::TABELLE_META . '` WHERE `meta_key` = :k'
        );
        $stmt->execute(['k' => $schluessel]);
        $wert = $stmt->fetchColumn();
        return $wert === false ? null : (string) $wert;
    }

    /**
     * Filter: zählt bei jedem Aufruf der öffentlichen Detailseite eines
     * Pferdes den Besuch mit und hängt einen Abschnitt mit der aktuellen
     * Aufrufzahl an.
     *
     * Zählweise unverändert aus `besucherstatistik` übernommen (#127): jeder
     * Aufruf zählt, auch der von Bots und Crawlern, und es wird bewusst NICHT
     * über Sitzung oder IP dedupliziert - das wäre eine personenbezogene
     * Speicherung. Diese Entscheidung ist Teil des Addons, nicht ein
     * Versäumnis, das eine Zusammenführung nebenbei "aufräumt".
     *
     * Der Rückgabewert wird von der View unescaped ausgegeben - Zahlen werden
     * daher hart nach int gecastet.
     */
    public function addDetailSection(array $sections, array $horse, array $horsePersons): array {
        $horseId = (int) $horse['id'];
        $db = Database::getInstance();

        $stmt = $db->prepare(
            'INSERT INTO `' . self::TABELLE_AUFRUFE . '` (`horse_id`, `views`, `last_viewed_at`)
             VALUES (:id, 1, NOW())
             ON DUPLICATE KEY UPDATE `views` = `views` + 1, `last_viewed_at` = NOW()'
        );
        $stmt->execute(['id' => $horseId]);

        $stmt = $db->prepare('SELECT `views` FROM `' . self::TABELLE_AUFRUFE . '` WHERE `horse_id` = :id');
        $stmt->execute(['id' => $horseId]);
        $views = (int) $stmt->fetchColumn();

        $sections[] = '<p style="color:var(--text-muted);font-size:0.9em;">👁 Dieses Profil wurde ' . $views . ' mal aufgerufen.</p>';
        return $sections;
    }

    /**
     * Filter: EINE Kachel im Admin-Dashboard (vorher zwei, #127). Der
     * Zugriffsschutz der Zielseite liegt in StatistikController - die Kachel
     * wird unabhängig von der Berechtigung angezeigt, ein Klick ohne
     * Berechtigung führt zur normalen 403-Seite des Kerns.
     */
    public function addDashboardTile(array $tiles): array {
        $tiles[] = [
            'url' => '/plugin/statistik-dashboard/statistik',
            'label' => 'Statistik',
            'icon' => '📈',
        ];
        return $tiles;
    }

    /**
     * Action: legt für ein neu angelegtes Pferd sofort eine Zähler-Zeile mit
     * 0 Aufrufen an, damit es in der Rangliste von Anfang an auftaucht statt
     * erst beim ersten Besuch. Läuft in try/catch-Isolation durch
     * HookManager::doAction() - ein Fehler hier blockiert nie den
     * eigentlichen Speichervorgang.
     */
    public function onHorseSaved(int $horseId, array $data, bool $isNew): void {
        if (!$isNew) {
            return;
        }
        $stmt = Database::getInstance()->prepare(
            'INSERT IGNORE INTO `' . self::TABELLE_AUFRUFE . '` (`horse_id`, `views`) VALUES (:id, 0)'
        );
        $stmt->execute(['id' => $horseId]);
    }

    /**
     * EIN Berechtigungsmodul für beide Abschnitte (#127). Die Zuweisungen des
     * alten Moduls `besucherstatistik` übernimmt install(), siehe
     * uebernahmeAusBesucherstatistik() - ohne das sähe nach dem Update
     * niemand mehr etwas, und zwar ohne dass eine Meldung erschiene.
     *
     * @return array<int, array{module:string, action:string, label:string, module_label:string}>
     */
    public function permissions(): array {
        return [
            [
                'module' => 'statistik-dashboard',
                'action' => 'view',
                'label' => 'Statistik einsehen',
                'module_label' => 'Statistik',
            ],
        ];
    }

    /**
     * @return array<int, array{method:string, path:string, callback:array}>
     */
    public function routes(): array {
        return [
            [
                'method' => 'GET',
                'path' => '/statistik',
                'callback' => [StatistikController::class, 'show'],
            ],
        ];
    }
}

class StatistikController extends BaseController {

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
        $this->requirePermission('statistik-dashboard', 'view');
    }

    public function show(): void {
        $db = Database::getInstance();

        // Inhalt als Fragment im Haupt-Layout über PluginPage (Addons#66):
        // Header, Navigation, Theme-Umschalter und Basis-Styling kommen vom
        // Framework, hier steht nur noch der eigentliche Seiteninhalt.
        // Das Kacheln-Raster ist addon-spezifische Geometrie und bleibt als
        // kleiner Style-Block; Farben ausschließlich über Theme-Variablen.
        $content = '<style>
            .tiles{display:grid;grid-template-columns:repeat(auto-fit, minmax(160px, 1fr));gap:1rem;margin-bottom:2rem;}
            .tile{background:var(--surface-muted);border-radius:var(--border-radius, 6px);padding:1rem;text-align:center;}
            .tile .num{font-size:1.8rem;font-weight:bold;}
            .tile .label{color:var(--text-muted);font-size:0.85rem;}
        </style>';

        $content .= '<div class="card">';
        $content .= '<h1>📈 Statistik</h1>';
        $content .= $this->hinweisAltesAddon($db);
        $content .= $this->abschnittBestand($db);
        $content .= $this->abschnittAufrufe($db);
        $content .= '<p><a href="/admin" class="btn btn-secondary">Zurück zum Dashboard</a></p>';
        $content .= '</div>';

        PluginPage::render('Statistik', $content);
    }

    /**
     * Abschnitt "Bestand": was in der Datenbank steht.
     */
    private function abschnittBestand(PDO $db): string {
        $statusCounts = $db->query(
            "SELECT status, COUNT(*) AS total FROM horses WHERE deleted_at IS NULL GROUP BY status"
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        // Lebensstatus ist seit dem Status-Split (Framework #188) orthogonal
        // zum Zuchtstatus - eigene Zählung statt eines status-Enum-Werts.
        $totalDeceased = (int) $db->query(
            "SELECT COUNT(*) FROM horses WHERE deleted_at IS NULL AND is_deceased = 1"
        )->fetchColumn();

        // Verteilung nach Deckstation, seit Addons#139 gegen die
        // zusammengeführte Kontaktliste (Framework#336): `breeding_stations`
        // gibt es nicht mehr, `horses.breeding_station_id` behält seinen Namen
        // und zeigt jetzt auf `contacts`. Die Kennzahl selbst ist dieselbe -
        // "Verteilung nach dem Kontakt in der Rolle Deckstation".
        //
        // Bewusst weiterhin NUR horses.breeding_station_id, nicht zusätzlich
        // horse_persons.station_contact_id: Die Spalte ist die AKTUELLE
        // Deckstation des Pferds (HorseController::saveHorsePersons() spiegelt
        // sie aus der jüngsten aktiven Zuordnungszeile), die Zuordnungszeilen
        // enthalten auch historische. Eine Bestandsverteilung, in der ein Pferd
        // bei jeder Station mitzählt, an der es je stand, summierte sich über
        // den Bestand hinaus.
        //
        // COALESCE-Reihenfolge unverändert: der Name des verknüpften Kontakts,
        // sonst der Freitext-Spiegel horses.breeding_station (der gesamte
        // Importbestand hat nur ihn), sonst "Unbekannt".
        //
        // contacts.name ist eine der immer-öffentlichen Spalten
        // (docs/kontaktliste-umstellung.md); ein SELECT * auf `contacts` gäbe
        // es hier nicht, auch wenn die Seite hinter statistik-dashboard.view
        // liegt.
        $stationDistribution = $db->query(
            "SELECT COALESCE(k.name, NULLIF(h.breeding_station, ''), 'Unbekannt') AS station, COUNT(*) AS total
             FROM horses h
             LEFT JOIN contacts k ON h.breeding_station_id = k.id AND k.deleted_at IS NULL
             WHERE h.deleted_at IS NULL
             GROUP BY station
             ORDER BY total DESC
             LIMIT 15"
        )->fetchAll(PDO::FETCH_ASSOC);

        $growthByYear = $db->query(
            "SELECT YEAR(created_at) AS yr, COUNT(*) AS total
             FROM horses
             WHERE deleted_at IS NULL AND created_at IS NOT NULL
             GROUP BY yr
             ORDER BY yr ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $topSires = $db->query(
            "SELECT COALESCE(sire.name, h.sire_name) AS display_name, COUNT(*) AS total
             FROM horses h
             LEFT JOIN horses sire ON h.sire_id = sire.id AND sire.deleted_at IS NULL
             WHERE h.deleted_at IS NULL AND (h.sire_id IS NOT NULL OR h.sire_name IS NOT NULL AND h.sire_name != '')
             GROUP BY display_name
             HAVING display_name IS NOT NULL
             ORDER BY total DESC
             LIMIT 10"
        )->fetchAll(PDO::FETCH_ASSOC);

        $topDams = $db->query(
            "SELECT COALESCE(dam.name, h.dam_name) AS display_name, COUNT(*) AS total
             FROM horses h
             LEFT JOIN horses dam ON h.dam_id = dam.id AND dam.deleted_at IS NULL
             WHERE h.deleted_at IS NULL AND (h.dam_id IS NOT NULL OR h.dam_name IS NOT NULL AND h.dam_name != '')
             GROUP BY display_name
             HAVING display_name IS NOT NULL
             ORDER BY total DESC
             LIMIT 10"
        )->fetchAll(PDO::FETCH_ASSOC);

        // Der Zuchtstatus (active/inactive) partitioniert weiterhin den
        // Gesamtbestand; Verstorben zählt quer dazu und darf NICHT in die
        // Summe eingehen (sonst zählten verstorbene Tiere doppelt).
        $totalActive = (int) ($statusCounts['active'] ?? 0);
        $totalInactive = (int) ($statusCounts['inactive'] ?? 0);
        $totalAll = $totalActive + $totalInactive;

        $html = '<h2>Bestand</h2>';

        $html .= '<div class="tiles">';
        $html .= '<div class="tile"><div class="num">' . $totalAll . '</div><div class="label">Pferde gesamt</div></div>';
        $html .= '<div class="tile"><div class="num">' . $totalActive . '</div><div class="label">Aktiv (Zucht)</div></div>';
        $html .= '<div class="tile"><div class="num">' . $totalInactive . '</div><div class="label">Inaktiv (Zucht)</div></div>';
        $html .= '<div class="tile"><div class="num">' . $totalDeceased . '</div><div class="label">Verstorben</div></div>';
        $html .= '</div>';

        $html .= '<h3>Verteilung nach Deckstation</h3>';
        // Der Zusatz sagt, was die Zeile "Unbekannt" bedeutet und warum ein
        // Name auftauchen kann, der in der Kontaktliste nicht steht: Seit
        // Framework#336 ist die Deckstation ein Kontakt, der Freitext-Spiegel
        // horses.breeding_station trägt aber weiterhin den Importbestand ohne
        // verknüpften Datensatz (Addons#139).
        $html .= '<p style="color:var(--text-muted);font-size:0.9rem;">Gezählt wird die aktuelle Deckstation je Pferd: '
            . 'der verknüpfte Kontakt, sonst der hinterlegte Freitext, sonst „Unbekannt".</p>';
        $html .= $this->renderCountTable($stationDistribution, 'station', 'Deckstation');

        $html .= '<h3>Wachstum der Datenbank über Zeit</h3>';
        $html .= '<div class="tabelle-scroll"><table><thead><tr><th>Jahr</th><th>Neu angelegte Pferde</th></tr></thead><tbody>';
        foreach ($growthByYear as $row) {
            $html .= '<tr><td>' . htmlspecialchars((string) $row['yr'], ENT_QUOTES, 'UTF-8') . '</td><td>' . (int) $row['total'] . '</td></tr>';
        }
        if (empty($growthByYear)) {
            $html .= '<tr><td colspan="2">Keine Daten vorhanden.</td></tr>';
        }
        $html .= '</tbody></table></div>';

        $html .= '<h3>Top-Blutlinien: meistgenutzte Väter</h3>';
        $html .= $this->renderCountTable($topSires, 'display_name', 'Vater');

        $html .= '<h3>Top-Blutlinien: meistgenutzte Mütter</h3>';
        $html .= $this->renderCountTable($topDams, 'display_name', 'Mutter');

        return $html;
    }

    /**
     * Abschnitt "Aufrufe": was Besucher tatsächlich ansehen - die frühere
     * Seite des Addons `besucherstatistik` (#127). Steht bewusst auf
     * derselben Seite wie der Bestand: "die meistgesehenen Pferde" ist eine
     * Kennzahl des Bestands, keine eigene Disziplin.
     */
    private function abschnittAufrufe(PDO $db): string {
        $rows = $db->query(
            'SELECT h.id, h.name, h.birth_year, COALESCE(v.views, 0) AS views
             FROM `horses` h
             LEFT JOIN `' . Plugin::TABELLE_AUFRUFE . '` v ON v.horse_id = h.id
             WHERE h.deleted_at IS NULL
             ORDER BY views DESC, h.name ASC
             LIMIT 50'
        )->fetchAll(PDO::FETCH_ASSOC);

        $html = '<h2>Aufrufe</h2>';
        $html .= '<p>Meistaufgerufene Pferde-Profile der öffentlichen Detailseite. Gezählt wird jeder Aufruf, auch der von Bots; eine Deduplizierung über Sitzung oder IP gibt es bewusst nicht (keine personenbezogene Speicherung).</p>';
        $html .= '<div class="tabelle-scroll"><table><thead><tr><th>#</th><th>Pferd</th><th>Geburtsjahr</th><th>Aufrufe</th></tr></thead><tbody>';

        $rank = 1;
        foreach ($rows as $row) {
            $html .= '<tr>';
            $html .= '<td>' . $rank++ . '</td>';
            $html .= '<td><a href="/horse?id=' . (int) $row['id'] . '">' . htmlspecialchars((string) $row['name'], ENT_QUOTES, 'UTF-8') . '</a></td>';
            $html .= '<td>' . htmlspecialchars((string) ($row['birth_year'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td>' . (int) $row['views'] . '</td>';
            $html .= '</tr>';
        }

        if (empty($rows)) {
            $html .= '<tr><td colspan="4">Noch keine Pferde vorhanden.</td></tr>';
        }

        $html .= '</tbody></table></div>';
        return $html;
    }

    /**
     * Warnt, solange das aufgegangene Addon `besucherstatistik` auf dieser
     * Instanz noch aktiviert ist (#127).
     *
     * Der Grund ist konkret: Beide zählen dann parallel in ihre je eigene
     * Tabelle und hängen beide einen Zähler an die öffentliche Detailseite -
     * der Besucher sieht die Zeile doppelt, und die Aufrufe, die in der
     * alten Tabelle auflaufen, sind nach der bereits gelaufenen Übernahme
     * verloren. Ein Hinweis an der Stelle, an der jemand die Zahlen
     * tatsächlich liest, erreicht den Admin verlässlicher als eine Zeile in
     * den Release Notes.
     */
    private function hinweisAltesAddon(PDO $db): string {
        $stmt = $db->prepare("SELECT COUNT(*) FROM `plugins` WHERE `slug` = :slug AND `enabled` = 1");
        $stmt->execute(['slug' => Plugin::SLUG_ALT]);
        if ((int) $stmt->fetchColumn() === 0) {
            return '';
        }

        return '<p style="border-left:4px solid var(--warning-fg);background:var(--warning-soft-bg);padding:0.75rem 1rem;">'
            . '<strong>Das Addon „Besucherstatistik“ ist noch aktiviert.</strong> '
            . 'Es ist seit Addons#127 in dieser Seite aufgegangen; seine Aufrufzahlen wurden bereits übernommen. '
            . 'Solange es aktiv bleibt, zählt es parallel in seine eigene Tabelle und zeigt auf der öffentlichen '
            . 'Detailseite einen zweiten Zähler an. Bitte unter <a href="/admin/plugins">Plugins verwalten</a> '
            . 'deaktivieren und das Verzeichnis entfernen.'
            . '</p>';
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function renderCountTable(array $rows, string $labelKey, string $labelHeading): string {
        $html = '<div class="tabelle-scroll"><table><thead><tr><th>' . htmlspecialchars($labelHeading, ENT_QUOTES, 'UTF-8') . '</th><th>Anzahl</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr><td>' . htmlspecialchars((string) $row[$labelKey], ENT_QUOTES, 'UTF-8') . '</td><td>' . (int) $row['total'] . '</td></tr>';
        }
        if (empty($rows)) {
            $html .= '<tr><td colspan="2">Keine Daten vorhanden.</td></tr>';
        }
        $html .= '</tbody></table></div>';
        return $html;
    }
}
