<?php
// pferd-des-tages/Plugin.php
//
// Addon für Hengstverzeichnis_Framework: löst
// Celestial0579/Hengstverzeichnis_Addons#135 ("Pferd des Tages auf der
// Startseite, mit einstellbaren Kriterien").
//
// Setzt Framework#356 voraus - bis v0.7 hatte ausgerechnet die meistbesuchte
// Seite des Verzeichnisses keinen einzigen Erweiterungspunkt; dieses Addon
// wäre ohne `home.sections_top` nur zu bauen gewesen, indem man
// src/Views/public_home.php im Kern anfasst.
//
// DIE DREI ENTSCHEIDUNGEN, DIE DIESES ADDON AUSMACHEN
//
// 1. "Des Tages" heisst: an einem Tag DASSELBE Pferd für alle Besucher.
//    Ein `ORDER BY RAND()` je Seitenaufruf ist etwas anderes - es fällt beim
//    ersten Neuladen auf, macht jede Zwischenspeicherung wertlos und lässt
//    geteilte Links ins Leere zeigen. Die Wahl wird deshalb je Kalendertag
//    genau EINMAL getroffen: deterministisch aus dem Datum abgeleitet (siehe
//    Auswahl::index()) und in `plugin_pferd_des_tages_wahl` festgehalten.
//
//    Beides zusammen, nicht eines von beidem. Die Ableitung aus dem Datum
//    macht die Wahl ohne Cron reproduzierbar und damit auch wettlauffrei:
//    Treffen zwei gleichzeitige erste Aufrufe des Tages dieselbe Entscheidung,
//    kann das `INSERT IGNORE` keinen Unterschied mehr verdecken. Das
//    Festhalten wiederum ist die Voraussetzung für die beiden Dinge, die eine
//    reine Ableitung nicht kann: keine Wiederholung innerhalb einer Schonfrist
//    und redaktionelle Vorgabe für ein bestimmtes Datum.
//
// 2. Die Anzeige kostet EINE Abfrage. Die Startseite ist die meistgeladene
//    Seite des Verzeichnisses; die Auswertung der Kriterien läuft deshalb
//    höchstens einmal je Kalendertag, nicht je Aufruf. Auch der Fall "die
//    Kriterien treffen nichts" wird festgehalten (Zeile mit horse_id = NULL) -
//    sonst liefe die teure Suche für den Rest des Tages bei jedem Aufruf
//    erneut ins Leere.
//
// 3. Fail-closed gegenüber der Katalogseite. Gezeigt wird nur, was der Kern
//    auch selbst zeigen würde: nur veröffentlichte, nicht gelöschte Pferde,
//    und nur, wenn die aktuelle Gruppe `horses.view` besitzt. Sonst zeigte die
//    Startseite ein Pferd, das der Katalog verbirgt. Geprüft wird bei jeder
//    Anzeige gegen den aktuellen Stand: Wird das Pferd des Tages um 14 Uhr
//    depubliziert, steht es nicht bis Mitternacht weiter auf der Startseite.
//
// Installation (lokal im Framework-Repo):
//   cp -r pferd-des-tages plugins/pferd-des-tages
// Danach unter Admin -> Plugins verwalten (/admin/plugins) aktivieren und der
// zuständigen Gruppe unter /admin/groups die Berechtigung
// "Pferd des Tages -> Verwalten" zuweisen.
//
// Siehe docs/plugin-development.md im Framework-Repo für die vollständige
// Hook-/Routen-/Berechtigungs-Referenz.

namespace Plugin\PferdDesTages;

use App\Controllers\BaseController;
use App\Database;
use App\Helper\MediaUrl;
use App\Permission\GroupMembership;
use App\Plugin\HookManager;
use App\Plugin\PluginAudit;
use App\Plugin\PluginPage;
use App\Router;
use PDO;

class Plugin {

    /** Der eigene Slug - zugleich Kategorie jedes Protokolleintrags (Kern-#352). */
    public const SLUG = 'pferd-des-tages';

    /** Adresse der Verwaltungsseite - an vier Stellen gebraucht. */
    public const VERWALTUNG = '/plugin/pferd-des-tages/verwaltung';

    public function register(HookManager $hooks): void {
        // `home.sections_top`, nicht `_bottom` (Framework#356): Der Kern
        // unterscheidet die beiden Einhängepunkte danach, was ein Addon tut -
        // wer etwas bewirbt, gehört über die Pferdeliste, wer
        // Zusatzinformationen nachreicht, darunter. Ein Pferd des Tages ist
        // das Erste, was ein Besucher sehen soll.
        $hooks->addFilter('home.sections_top', [$this, 'startseiteOben']);
        $hooks->addFilter('admin.dashboard_tiles', [$this, 'dashboardKachel']);
    }

    /**
     * Kern-#75: Der PluginManager ruft install() bei der Aktivierung und nach
     * jedem Addon-Update genau einmal auf - kein DDL in register(), das liefe
     * bei JEDEM Request.
     *
     * Kein uninstall(): Dieses Addon hinterlässt in Kern-Tabellen nichts, was
     * sich nicht im Manifest aufzählen liesse. Die drei eigenen Tabellen
     * stehen unter "owns" (Kern-#338), und die Protokolleinträge unter der
     * Kategorie `pferd-des-tages` bleiben bewusst stehen - ein Nachweis, den
     * das Deinstallieren mitnimmt, ist keiner.
     */
    public function install(): void {
        $db = Database::getInstance();

        // Die getroffene Wahl je Kalendertag. PRIMARY KEY auf `datum`: Genau
        // eine Wahl je Tag, und der Wettlauf zweier gleichzeitiger erster
        // Aufrufe endet damit im INSERT IGNORE statt in zwei Zeilen.
        //
        // horse_id ist NULLABLE, und das ist kein Versehen: Die Zeile mit
        // NULL bedeutet "für diesen Tag haben die Kriterien nichts getroffen".
        // Ohne diesen Merker liefe die Kandidatensuche für den Rest des Tages
        // bei jedem einzelnen Aufruf der Startseite erneut ins Leere.
        //
        // ON DELETE CASCADE: Ein endgültig gelöschtes Pferd nimmt seine
        // Wahl-Zeilen mit. Alles andere wäre eine Karteileiche, die beim
        // nächsten Blick in die Vorgabenliste als leerer Eintrag erschiene.
        $db->exec(
            'CREATE TABLE IF NOT EXISTS `plugin_pferd_des_tages_wahl` (
                `datum` DATE NOT NULL PRIMARY KEY,
                `horse_id` INT NULL DEFAULT NULL,
                `fest` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_pdt_wahl_pferd` (`horse_id`),
                FOREIGN KEY (`horse_id`) REFERENCES `horses`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        // Dauerhaft ausgenommene Pferde - auf Wunsch des Besitzers, oder weil
        // die Daten unvollständig sind.
        $db->exec(
            'CREATE TABLE IF NOT EXISTS `plugin_pferd_des_tages_ausschluss` (
                `horse_id` INT NOT NULL PRIMARY KEY,
                `grund` VARCHAR(200) NULL DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`horse_id`) REFERENCES `horses`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        // Eigene Konfigurationstabelle statt Zeilen in der Kern-Tabelle
        // `settings` - dieselbe Begründung wie bei `plugin_kontaktanfrage_config`
        // und `plugin_statistik_dashboard_meta`: deren Schlüsselspalte ist
        // VARCHAR(50), und die Systemeinstellungen sind eine redaktionell
        // gepflegte Oberfläche, in die ein Addon keine Fremdschlüssel streuen
        // sollte. Deshalb ist "owns.settings" im Manifest leer und
        // "owns.tables" trägt diese Tabelle.
        $db->exec(
            'CREATE TABLE IF NOT EXISTS `plugin_pferd_des_tages_config` (
                `meta_key` VARCHAR(64) NOT NULL PRIMARY KEY,
                `meta_value` TEXT NULL,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /**
     * Filter `home.sections_top` (Framework#356).
     *
     * $featuredHorses sind die drei Pferde, die der Kern selbst zeigt - hier
     * bewusst ungenutzt: Sie sind `ORDER BY id DESC LIMIT 3`, also die
     * zuletzt angelegten, und haben mit den eingestellten Kriterien nichts zu
     * tun. Der Parameter steht in der Signatur, weil der Hook ihn übergibt.
     *
     * Das Fragment wird UNESCAPED ausgegeben - jede dynamische Angabe darin
     * geht durch htmlspecialchars(), siehe Kasten::html().
     *
     * @param array<int, string> $sections
     * @param array<int, array<string, mixed>> $featuredHorses
     * @return array<int, string>
     */
    public function startseiteOben(array $sections, array $featuredHorses = []): array {
        // Fail-closed, und ausdrücklich noch einmal hier: Dass dieser Filter
        // nur auf der Startseite läuft, ist kein Rechteschutz. Besitzt die
        // Gast-Gruppe `horses.view` nicht, zeigt der Katalog keine Pferde -
        // dann darf die Startseite auch keines hervorheben, sonst ist das
        // Addon das Loch in einer bewussten Einstellung des Betreibers.
        if (!GroupMembership::hasPermission((int) ($_SESSION['user_id'] ?? 0), 'horses', 'view')) {
            return $sections;
        }

        $pferd = Auswahl::fuerTag(Auswahl::heute());
        if ($pferd === null) {
            // Kein Treffer heisst kein Kasten (#135): kein leerer Rahmen,
            // keine Fehlermeldung auf der Startseite. Der Besucher hat mit
            // den Kriterien des Betreibers nichts zu tun.
            return $sections;
        }

        $sections[] = Kasten::html($pferd);
        return $sections;
    }

    /**
     * @param array<int, array{url:string,label:string,icon:string}> $tiles
     * @return array<int, array{url:string,label:string,icon:string}>
     */
    public function dashboardKachel(array $tiles): array {
        // Fail-closed wie der Abschnitt selbst: Wer die Seite nicht öffnen
        // darf, bekommt auch keine Kachel, die in ein 403 führt.
        if (!GroupMembership::hasPermission((int) ($_SESSION['user_id'] ?? 0), self::SLUG, 'manage')) {
            return $tiles;
        }

        $tiles[] = [
            'url' => self::VERWALTUNG,
            'label' => 'Pferd des Tages',
            'icon' => '🐴',
        ];
        return $tiles;
    }

    /**
     * @return array<int, array{module:string, action:string, label:string, module_label:string}>
     */
    public function permissions(): array {
        return [
            [
                'module' => self::SLUG,
                'action' => 'manage',
                'label' => 'Kriterien, Ausschlussliste und Vorgaben pflegen',
                'module_label' => 'Pferd des Tages',
            ],
        ];
    }

    /**
     * @return array<int, array{method:string, path:string, callback:array}>
     */
    public function routes(): array {
        return [
            ['method' => 'GET', 'path' => '/verwaltung', 'callback' => [VerwaltungController::class, 'index']],
            ['method' => 'POST', 'path' => '/verwaltung/kriterien', 'callback' => [VerwaltungController::class, 'kriterien']],
            ['method' => 'POST', 'path' => '/verwaltung/neu-waehlen', 'callback' => [VerwaltungController::class, 'neuWaehlen']],
            ['method' => 'POST', 'path' => '/verwaltung/ausschluss', 'callback' => [VerwaltungController::class, 'ausschlussHinzufuegen']],
            ['method' => 'POST', 'path' => '/verwaltung/ausschluss/entfernen', 'callback' => [VerwaltungController::class, 'ausschlussEntfernen']],
            ['method' => 'POST', 'path' => '/verwaltung/vorgabe', 'callback' => [VerwaltungController::class, 'vorgabeSetzen']],
            ['method' => 'POST', 'path' => '/verwaltung/vorgabe/entfernen', 'callback' => [VerwaltungController::class, 'vorgabeEntfernen']],
        ];
    }
}

/**
 * Zugriff auf die eigene Konfigurationstabelle. Bewusst schmal: ein
 * Schlüssel-Wert-Paar, gelesen mit einem Request-Cache, damit die Startseite
 * die Kriterien nicht zweimal holt.
 */
final class Konfiguration {

    /** @var array<string, ?string>|null Request-Cache, `null` = noch nicht geladen. */
    private static ?array $cache = null;

    private function __construct() {}

    public static function lesen(string $schluessel): ?string {
        if (self::$cache === null) {
            self::$cache = [];
            try {
                $zeilen = Database::getInstance()
                    ->query('SELECT meta_key, meta_value FROM `plugin_pferd_des_tages_config`')
                    ->fetchAll(PDO::FETCH_ASSOC);
                foreach ($zeilen as $zeile) {
                    self::$cache[(string) $zeile['meta_key']] = $zeile['meta_value'] === null
                        ? null
                        : (string) $zeile['meta_value'];
                }
            } catch (\Throwable $e) {
                // Tabelle noch nicht angelegt (Aktivierung läuft gerade) -
                // dann gelten die Standardkriterien, nicht "gar nichts".
                self::$cache = [];
            }
        }

        return self::$cache[$schluessel] ?? null;
    }

    public static function schreiben(string $schluessel, string $wert): void {
        $stmt = Database::getInstance()->prepare(
            'INSERT INTO `plugin_pferd_des_tages_config` (meta_key, meta_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)'
        );
        $stmt->execute([$schluessel, $wert]);
        self::$cache = null;
    }
}

/**
 * Die einstellbaren Kriterien (#135): Was gehört überhaupt in den Topf, aus
 * dem gezogen wird.
 *
 * Alles liegt als EIN JSON-Wert in der Konfigurationstabelle. Der Grund ist
 * nicht Bequemlichkeit: Die Kriterien werden immer gemeinsam gelesen und
 * gemeinsam gespeichert, und ein halb geschriebener Satz Kriterien wäre ein
 * Zustand, den es nicht geben soll.
 */
final class Kriterien {

    public const SCHLUESSEL = 'kriterien';

    /** Obergrenze der Schonfrist - ein Jahr, danach ist "keine Wiederholung" keine Regel mehr, sondern eine Sackgasse. */
    public const MAX_SCHONFRIST = 365;

    /** Plausibler Bereich für Geburtsjahr-Grenzen, gleich dem des Kern-Formulars. */
    public const JAHR_MIN = 1900;
    public const JAHR_MAX = 2155;

    /**
     * Die Grundeinstellung, bevor der Betreiber irgendetwas gewählt hat.
     *
     * `nur_mit_foto` steht auf true: Ein Pferd des Tages ohne Bild verfehlt
     * den Zweck (#135). `verstorbene_einschliessen` steht auf false - eine
     * Startseite, die einen verstorbenen Hengst feiert, ohne dass jemand das
     * ausgewählt hat, ist ein Fehlgriff, den niemand kommen sieht.
     *
     * @var array<string, mixed>
     */
    public const STANDARD = [
        'nur_mit_foto' => true,
        'status' => '',
        'sex' => '',
        'farbe' => '',
        'rasse' => '',
        'jahr_von' => null,
        'jahr_bis' => null,
        'verstorbene_einschliessen' => false,
        'station_id' => null,
        'zuechter_id' => null,
        'schonfrist_tage' => 30,
        // Kür-Kriterien: wirken nur, wenn das jeweilige Addon installiert ist.
        'nur_ausgezeichnete' => false,
        'nur_verkaeuflich' => false,
        'min_aufrufe' => 0,
    ];

    public const GESCHLECHTER = [
        'stallion' => 'Hengst',
        'mare' => 'Stute',
        'gelding' => 'Wallach',
    ];

    public const STATUS = [
        'active' => 'aktiv',
        'inactive' => 'inaktiv',
    ];

    /**
     * Die Kür-Kriterien und die Tabelle, an der jedes hängt (#135).
     *
     * An einer Stelle, weil drei Stellen sie brauchen: die Anzeige (nur
     * einblenden, wenn das Addon da ist), das Speichern (ein Feld, das nicht
     * angezeigt wurde, darf seinen Wert nicht verlieren) und die Auswertung.
     *
     * @var array<string, string>
     */
    public const KUER = [
        'nur_ausgezeichnete' => 'plugin_titel_praemierungen',
        'nur_verkaeuflich' => 'plugin_verkaufsboerse_listings',
        'min_aufrufe' => 'plugin_statistik_dashboard_views',
    ];

    private function __construct() {}

    /**
     * @return array<string, mixed> immer vollständig - fehlende Schlüssel
     *   kommen aus STANDARD, damit ein später ergänztes Kriterium einen
     *   gespeicherten Altbestand nicht unbrauchbar macht.
     */
    public static function laden(): array {
        $roh = Konfiguration::lesen(self::SCHLUESSEL);
        if ($roh === null || trim($roh) === '') {
            return self::STANDARD;
        }

        $daten = json_decode($roh, true);
        if (!is_array($daten)) {
            return self::STANDARD;
        }

        return array_merge(self::STANDARD, array_intersect_key($daten, self::STANDARD));
    }

    /** @param array<string, mixed> $kriterien */
    public static function speichern(array $kriterien): void {
        Konfiguration::schreiben(
            self::SCHLUESSEL,
            (string) json_encode(array_intersect_key($kriterien, self::STANDARD), JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * Rohe Formulareingabe auf den erlaubten Wertebereich abbilden.
     *
     * Bewusst tolerant statt abweisend: Ein unplausibles Geburtsjahr wird zu
     * "keine Grenze", ein unbekanntes Geschlecht zu "egal". Die Kriterien sind
     * kein Datensatz, sondern ein Filter - ein Tippfehler darf hier die
     * Startseite nicht anhalten, er darf nur nichts einschränken.
     *
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public static function ausEingabe(array $post): array {
        $text = static fn(string $feld, int $max): string => mb_substr(
            trim((string) ($post[$feld] ?? '')),
            0,
            $max
        );

        $auswahl = static function (string $feld, array $erlaubt) use ($post): string {
            $wert = (string) ($post[$feld] ?? '');
            return isset($erlaubt[$wert]) ? $wert : '';
        };

        $jahr = static function (string $feld) use ($post): ?int {
            $wert = filter_var(
                $post[$feld] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => self::JAHR_MIN, 'max_range' => self::JAHR_MAX]]
            );
            return is_int($wert) ? $wert : null;
        };

        $id = static function (string $feld) use ($post): ?int {
            $wert = filter_var($post[$feld] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            return is_int($wert) ? $wert : null;
        };

        $von = $jahr('jahr_von');
        $bis = $jahr('jahr_bis');
        // Vertauschte Grenzen ergäben eine leere Menge, also dauerhaft keinen
        // Kasten - und der Betreiber suchte den Fehler auf der Startseite
        // statt in seiner Eingabe. Deshalb wird getauscht, nicht abgewiesen.
        if ($von !== null && $bis !== null && $von > $bis) {
            [$von, $bis] = [$bis, $von];
        }

        $schonfrist = filter_var(
            $post['schonfrist_tage'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0, 'max_range' => self::MAX_SCHONFRIST]]
        );

        $minAufrufe = filter_var(
            $post['min_aufrufe'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0, 'max_range' => 1000000]]
        );

        return [
            'nur_mit_foto' => !empty($post['nur_mit_foto']),
            'status' => $auswahl('status', self::STATUS),
            'sex' => $auswahl('sex', self::GESCHLECHTER),
            'farbe' => $text('farbe', 50),
            'rasse' => $text('rasse', 100),
            'jahr_von' => $von,
            'jahr_bis' => $bis,
            'verstorbene_einschliessen' => !empty($post['verstorbene_einschliessen']),
            'station_id' => $id('station_id'),
            'zuechter_id' => $id('zuechter_id'),
            'schonfrist_tage' => is_int($schonfrist) ? $schonfrist : self::STANDARD['schonfrist_tage'],
            'nur_ausgezeichnete' => !empty($post['nur_ausgezeichnete']),
            'nur_verkaeuflich' => !empty($post['nur_verkaeuflich']),
            'min_aufrufe' => is_int($minAufrufe) ? $minAufrufe : 0,
        ];
    }

    /**
     * Übersetzt die Kriterien in WHERE-Bedingungen auf `horses h`.
     *
     * Die Kür-Kriterien (#135: "als Kür, nicht als Voraussetzung") hängen an
     * Tabellen fremder Addons. Fehlt das Addon, fällt das Kriterium weg,
     * statt das Pferd des Tages lahmzulegen - deshalb die Existenzprüfung
     * statt eines Vertrauens darauf, dass die Tabelle schon da sein wird.
     *
     * @param array<string, mixed> $k
     * @return array{0: array<int, string>, 1: array<int, mixed>}
     */
    public static function bedingungen(array $k): array {
        $wo = [];
        $werte = [];

        if (!empty($k['nur_mit_foto'])) {
            $wo[] = "h.image_url IS NOT NULL AND h.image_url <> ''";
        }
        if (($k['status'] ?? '') !== '') {
            $wo[] = 'h.status = ?';
            $werte[] = $k['status'];
        }
        if (($k['sex'] ?? '') !== '') {
            $wo[] = 'h.sex = ?';
            $werte[] = $k['sex'];
        }
        if (($k['farbe'] ?? '') !== '') {
            $wo[] = 'h.color = ?';
            $werte[] = $k['farbe'];
        }
        if (($k['rasse'] ?? '') !== '') {
            $wo[] = 'h.breed = ?';
            $werte[] = $k['rasse'];
        }
        if ($k['jahr_von'] !== null) {
            $wo[] = 'h.birth_year >= ?';
            $werte[] = (int) $k['jahr_von'];
        }
        if ($k['jahr_bis'] !== null) {
            $wo[] = 'h.birth_year <= ?';
            $werte[] = (int) $k['jahr_bis'];
        }
        if (empty($k['verstorbene_einschliessen'])) {
            // Seit dem Status-Split (#188) steht der Lebensstatus in
            // is_deceased, NICHT mehr in status - `status = 'deceased'` gibt
            // es nicht mehr und träfe hier nie.
            $wo[] = 'h.is_deceased = 0';
        }
        if ($k['station_id'] !== null) {
            $wo[] = 'h.breeding_station_id = ?';
            $werte[] = (int) $k['station_id'];
        }
        if ($k['zuechter_id'] !== null) {
            $wo[] = 'EXISTS (SELECT 1 FROM horse_persons hp'
                . " WHERE hp.horse_id = h.id AND hp.role = 'breeder' AND hp.contact_id = ?)";
            $werte[] = (int) $k['zuechter_id'];
        }

        if (!empty($k['nur_ausgezeichnete']) && Fremdtabelle::vorhanden(self::KUER['nur_ausgezeichnete'])) {
            $wo[] = 'EXISTS (SELECT 1 FROM `plugin_titel_praemierungen` tp WHERE tp.horse_id = h.id)';
        }
        if (!empty($k['nur_verkaeuflich']) && Fremdtabelle::vorhanden(self::KUER['nur_verkaeuflich'])) {
            // Dieselbe Definition von "aktiv", die das Verkaufsbörsen-Addon
            // auf der Detailseite benutzt: kein listed_until in der
            // Vergangenheit.
            $wo[] = 'EXISTS (SELECT 1 FROM `plugin_verkaufsboerse_listings` vb'
                . ' WHERE vb.horse_id = h.id AND (vb.listed_until IS NULL OR vb.listed_until >= CURDATE()))';
        }
        if ((int) ($k['min_aufrufe'] ?? 0) > 0 && Fremdtabelle::vorhanden(self::KUER['min_aufrufe'])) {
            $wo[] = 'EXISTS (SELECT 1 FROM `plugin_statistik_dashboard_views` sv'
                . ' WHERE sv.horse_id = h.id AND sv.views >= ?)';
            $werte[] = (int) $k['min_aufrufe'];
        }

        return [$wo, $werte];
    }
}

/**
 * "Gibt es diese Tabelle?" - für die Kür-Kriterien aus fremden Addons.
 *
 * Das Ergebnis wird für den Request gecacht; gebraucht wird es ohnehin nur im
 * langsamen Pfad, also höchstens einmal je Kalendertag.
 */
final class Fremdtabelle {

    /** @var array<string, bool> */
    private static array $cache = [];

    private function __construct() {}

    public static function vorhanden(string $name): bool {
        if (isset(self::$cache[$name])) {
            return self::$cache[$name];
        }

        try {
            $db = Database::getInstance();
            $ergebnis = $db->query('SHOW TABLES LIKE ' . $db->quote($name));
            self::$cache[$name] = $ergebnis !== false && $ergebnis->rowCount() > 0;
        } catch (\Throwable $e) {
            self::$cache[$name] = false;
        }

        return self::$cache[$name];
    }
}

/**
 * Die Wahl des Tages: treffen, festhalten, wiederfinden.
 */
final class Auswahl {

    /**
     * Der Pfeffer der Ableitung. Er macht die Reihenfolge über die Tage
     * unvorhersehbar, ohne den Zufall wieder hereinzuholen: Aus demselben
     * Datum folgt immer derselbe Index.
     */
    private const PFEFFER = 'pferd-des-tages';

    /** Spalten, die der Kasten braucht - kein SELECT *. */
    private const SPALTEN = 'h.id, h.name, h.color, h.breed, h.birth_year, h.sex, h.image_url, h.description';

    private function __construct() {}

    public static function heute(): string {
        return date('Y-m-d');
    }

    /**
     * Das Pferd des Tages für ein Datum - oder `null`, wenn keines feststeht
     * bzw. das festgehaltene inzwischen nicht mehr öffentlich sichtbar ist.
     *
     * Der Normalfall ist EINE Abfrage: Die Zeile für heute steht bereits, und
     * der LEFT JOIN prüft die Sichtbarkeit im selben Zug.
     *
     * @return array<string, mixed>|null
     */
    public static function fuerTag(string $datum): ?array {
        $zeile = self::gespeichert($datum);
        if ($zeile !== null) {
            return self::pferdAus($zeile);
        }

        // Langsamer Pfad - höchstens einmal je Kalendertag, danach steht die
        // Zeile (auch die mit horse_id = NULL für "nichts getroffen").
        self::treffen($datum);

        $zeile = self::gespeichert($datum);
        return $zeile === null ? null : self::pferdAus($zeile);
    }

    /**
     * Die festgehaltene Zeile samt Pferdedaten, wenn das Pferd JETZT
     * öffentlich sichtbar ist.
     *
     * Der LEFT JOIN trägt die Bedingungen `is_published = 1` und
     * `deleted_at IS NULL` in der ON-Klausel, nicht im WHERE: So bleibt die
     * Zeile unterscheidbar in "es gibt noch keine Wahl für heute" (kein
     * Ergebnis) und "die Wahl steht, taugt aber nicht mehr" (Ergebnis mit
     * id = NULL). Ohne diese Unterscheidung liefe die Kandidatensuche nach
     * einer Depublikation bei jedem Aufruf erneut.
     *
     * @return array<string, mixed>|null
     */
    private static function gespeichert(string $datum): ?array {
        try {
            $stmt = Database::getInstance()->prepare(
                'SELECT w.horse_id AS gewaehlt, ' . self::SPALTEN . '
                 FROM `plugin_pferd_des_tages_wahl` w
                 LEFT JOIN horses h
                        ON h.id = w.horse_id AND h.is_published = 1 AND h.deleted_at IS NULL
                 WHERE w.datum = ?'
            );
            $stmt->execute([$datum]);
            $zeile = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            // Tabelle fehlt (Aktivierung läuft gerade): kein Kasten, kein
            // Fehler auf der Startseite.
            return null;
        }

        return $zeile === false ? null : $zeile;
    }

    /**
     * @param array<string, mixed> $zeile
     * @return array<string, mixed>|null
     */
    private static function pferdAus(array $zeile): ?array {
        return $zeile['id'] === null ? null : $zeile;
    }

    /**
     * Die Wahl für ein Datum treffen und festhalten.
     *
     * `INSERT IGNORE`, nicht `REPLACE`: Steht bereits eine Zeile - weil ein
     * zweiter gleichzeitiger Aufruf schneller war oder weil die Redaktion für
     * dieses Datum etwas vorgegeben hat -, gewinnt sie. Diese Methode ist die
     * automatische Wahl, und die tritt hinter eine vorhandene zurück.
     */
    private static function treffen(string $datum): void {
        $kriterien = Kriterien::laden();

        $kandidaten = self::kandidaten($kriterien);
        $kandidaten = self::ohneSchonfrist($kandidaten, (int) $kriterien['schonfrist_tage'], $datum);

        $gewaehlt = $kandidaten === []
            ? null
            : $kandidaten[self::index($datum, count($kandidaten))];

        try {
            $stmt = Database::getInstance()->prepare(
                'INSERT IGNORE INTO `plugin_pferd_des_tages_wahl` (datum, horse_id, fest) VALUES (?, ?, 0)'
            );
            $stmt->execute([$datum, $gewaehlt]);
            $eingefuegt = $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            return;
        }

        if (!$eingefuegt) {
            // Ein anderer Aufruf war schneller - dann hat er dasselbe Pferd
            // eingetragen (die Ableitung ist deterministisch), und ein
            // zweiter Protokolleintrag wäre eine Doppelung.
            return;
        }

        // Kern-#352: Auch die automatische Wahl ist eine schreibende Aktion.
        // Ein Eintrag je Kalendertag ersäuft das Protokoll nicht, beantwortet
        // aber die Frage "warum stand am 3. dieses Pferd da" ohne Raten.
        PluginAudit::log(
            Plugin::SLUG,
            $gewaehlt === null ? 'Pferd des Tages: kein Kandidat' : 'Pferd des Tages ermittelt',
            $gewaehlt === null ? 'Tag ' . $datum : 'Pferd ' . self::bezug($gewaehlt),
            'Datum ' . $datum . ', Kandidaten: ' . count($kandidaten)
        );
    }

    /**
     * Die Grundmenge: veröffentlicht, nicht gelöscht, nicht ausgeschlossen,
     * plus die eingestellten Kriterien.
     *
     * `ORDER BY h.id` ist nicht Kosmetik: Die Ableitung aus dem Datum wählt
     * einen INDEX in dieser Liste - ohne feste Reihenfolge wäre dieselbe
     * Rechnung an zwei Aufrufen zwei verschiedene Pferde.
     *
     * @param array<string, mixed> $kriterien
     * @return array<int, int>
     */
    private static function kandidaten(array $kriterien): array {
        [$wo, $werte] = Kriterien::bedingungen($kriterien);

        $bedingungen = array_merge(
            [
                'h.is_published = 1',
                'h.deleted_at IS NULL',
                'NOT EXISTS (SELECT 1 FROM `plugin_pferd_des_tages_ausschluss` a WHERE a.horse_id = h.id)',
            ],
            $wo
        );

        try {
            $stmt = Database::getInstance()->prepare(
                'SELECT h.id FROM horses h WHERE ' . implode(' AND ', $bedingungen) . ' ORDER BY h.id ASC'
            );
            $stmt->execute($werte);
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Keine Wiederholung, solange die Auswahl nicht erschöpft ist (#135).
     *
     * Der zweite Teil des Satzes ist der wichtige: Sind alle Kandidaten
     * innerhalb der Schonfrist schon einmal drangewesen, gilt die Schonfrist
     * für diesen Tag NICHT. Sonst verschwände der Kasten bei einem kleinen
     * Bestand nach wenigen Tagen von der Startseite, und der Betreiber suchte
     * den Fehler in den Kriterien.
     *
     * @param array<int, int> $kandidaten
     * @return array<int, int>
     */
    private static function ohneSchonfrist(array $kandidaten, int $tage, string $datum): array {
        if ($tage <= 0 || $kandidaten === []) {
            return $kandidaten;
        }

        $grenze = date('Y-m-d', (int) strtotime($datum . ' -' . $tage . ' days'));

        try {
            $stmt = Database::getInstance()->prepare(
                'SELECT horse_id FROM `plugin_pferd_des_tages_wahl`
                 WHERE horse_id IS NOT NULL AND datum >= ? AND datum <= ?'
            );
            $stmt->execute([$grenze, $datum]);
            $jung = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (\Throwable $e) {
            return $kandidaten;
        }

        $rest = array_values(array_diff($kandidaten, $jung));
        return $rest === [] ? $kandidaten : $rest;
    }

    /**
     * Der Index in die Kandidatenliste, abgeleitet aus dem Datum.
     *
     * crc32 statt rand(): Aus demselben Datum folgt derselbe Index - über
     * jeden Aufruf, jeden Webserver-Prozess und jede gleichzeitige Anfrage
     * hinweg. Genau das ist der Unterschied zwischen "Pferd des Tages" und
     * "zufälliges Pferd". Eine kryptografische Streufunktion wäre hier keine
     * Verbesserung: Es geht um Reproduzierbarkeit, nicht um Geheimhaltung.
     */
    private static function index(string $datum, int $anzahl): int {
        return $anzahl <= 1 ? 0 : (int) (crc32(self::PFEFFER . '|' . $datum) % $anzahl);
    }

    /** Bezug für das Protokoll - der Datensatz, nie ein personenbezogener Inhalt. */
    public static function bezug(int $horseId): string {
        return '#' . $horseId;
    }

    /** Verwirft die Wahl eines Tages, damit sie neu getroffen wird. */
    public static function verwerfen(string $datum): void {
        $stmt = Database::getInstance()->prepare('DELETE FROM `plugin_pferd_des_tages_wahl` WHERE datum = ?');
        $stmt->execute([$datum]);
    }

    /**
     * Redaktionelle Vorgabe für ein Datum - Fohlenschau, Jubiläum,
     * Verbandstermin (#135).
     *
     * Setzt `fest = 1`, damit die Verwaltungsseite die Vorgaben von den
     * automatisch getroffenen Wahlen unterscheiden kann. Sie überschreibt eine
     * vorhandene Wahl bewusst: Wer für den 14. ein Pferd vorgibt, meint das
     * auch dann, wenn für den 14. schon automatisch gewählt wurde.
     */
    public static function festlegen(string $datum, int $horseId): void {
        $stmt = Database::getInstance()->prepare(
            'INSERT INTO `plugin_pferd_des_tages_wahl` (datum, horse_id, fest) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE horse_id = VALUES(horse_id), fest = 1'
        );
        $stmt->execute([$datum, $horseId]);
    }
}

/**
 * Das Fragment für die Startseite.
 *
 * Der Hook gibt es UNESCAPED aus (Framework#356) - die Escaping-Verantwortung
 * liegt hier, und zwar vollständig: Jede Angabe, die aus der Datenbank kommt,
 * geht durch htmlspecialchars(). Das Bild kommt ausschliesslich über
 * App\Helper\MediaUrl::horseImage(); ein roher /uploads/-Pfad umginge den
 * Einbettungsschutz und war der Gegenstand des Sicherheitsgutachtens
 * GHSA-xrrq-9j94-fr5g.
 */
final class Kasten {

    /** Länge des Beschreibungs-Anrisses. Die ganze Beschreibung steht auf der Detailseite. */
    private const ANRISS = 220;

    private function __construct() {}

    /** @param array<string, mixed> $pferd */
    public static function html(array $pferd): string {
        $esc = static fn($wert): string => htmlspecialchars((string) $wert, ENT_QUOTES, 'UTF-8');

        $id = (int) $pferd['id'];
        $name = $esc($pferd['name']);
        // Reiner Integer - keine Angabe aus der Datenbank in der Adresse.
        $link = '/horse?id=' . $id;
        $bild = MediaUrl::horseImage($pferd);

        $angaben = [];
        foreach (['breed', 'color'] as $feld) {
            if (!empty($pferd[$feld])) {
                $angaben[] = $esc($pferd[$feld]);
            }
        }
        if (!empty($pferd['sex']) && isset(Kriterien::GESCHLECHTER[$pferd['sex']])) {
            $angaben[] = $esc(Kriterien::GESCHLECHTER[$pferd['sex']]);
        }
        if (!empty($pferd['birth_year'])) {
            $angaben[] = 'Jahrgang ' . (int) $pferd['birth_year'];
        }

        $html = '<section class="card" style="margin-bottom:1.5rem;">';
        $html .= '<h2 style="margin-top:0;">🐴 Pferd des Tages</h2>';
        $html .= '<div style="display:flex;flex-wrap:wrap;gap:1.5rem;align-items:flex-start;">';

        if ($bild !== null) {
            $html .= '<a href="' . $link . '" style="flex:0 0 auto;">'
                . '<img src="' . $esc($bild) . '" alt="' . $name . '" loading="lazy"'
                . ' style="width:240px;max-width:100%;height:auto;border-radius:var(--border-radius);">'
                . '</a>';
        }

        $html .= '<div style="flex:1 1 260px;min-width:0;">';
        $html .= '<h3 style="margin:0 0 0.35rem 0;"><a href="' . $link . '">' . $name . '</a></h3>';

        if ($angaben !== []) {
            $html .= '<p style="margin:0 0 0.6rem 0;color:var(--text-muted);">'
                . implode(' &middot; ', $angaben) . '</p>';
        }

        if (!empty($pferd['description'])) {
            $html .= '<p style="margin:0 0 0.9rem 0;">'
                . $esc(self::kuerzen((string) $pferd['description'])) . '</p>';
        }

        $html .= '<p style="margin:0;"><a class="btn" href="' . $link . '">Profil ansehen</a></p>';
        $html .= '</div></div></section>';

        return $html;
    }

    private static function kuerzen(string $text): string {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        return mb_strlen($text) <= self::ANRISS
            ? $text
            : mb_substr($text, 0, self::ANRISS) . '…';
    }
}

/**
 * Verwaltungsseite: Kriterien, Ausschlussliste, redaktionelle Vorgaben.
 *
 * Zugriffsschutz über die selbst registrierte Berechtigung
 * `pferd-des-tages.manage` - Plugin-Routen laufen NICHT automatisch durch
 * checkAuth()/requireAdmin() (siehe docs/plugin-development.md).
 */
class VerwaltungController extends BaseController {

    /** Wie viele Vorgaben/Wahlen die Seite zeigt. */
    private const LETZTE_WAHLEN = 14;

    /** @var array<string, string> */
    private const MELDUNGEN = [
        'gespeichert' => 'Kriterien gespeichert. Die heutige Wahl bleibt bestehen - sie gilt für den ganzen Tag. Mit „Heute neu wählen" greifen die neuen Kriterien sofort.',
        'neu-gewaehlt' => 'Die heutige Wahl wurde verworfen und nach den aktuellen Kriterien neu getroffen.',
        'ausgeschlossen' => 'Pferd dauerhaft ausgenommen.',
        'ausschluss-weg' => 'Ausnahme aufgehoben.',
        'vorgabe-gesetzt' => 'Vorgabe gespeichert.',
        'vorgabe-weg' => 'Vorgabe entfernt.',
        'kein-pferd' => 'Kein Pferd ausgewählt oder das Pferd gibt es nicht.',
        'kein-datum' => 'Kein gültiges Datum angegeben (Format JJJJ-MM-TT).',
    ];

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
        $this->requirePermission(Plugin::SLUG, 'manage');
    }

    public function index(): void {
        $csrf = htmlspecialchars(Router::generateCsrfToken(), ENT_QUOTES, 'UTF-8');

        $inhalt = self::meldung();
        $inhalt .= $this->heuteKarte($csrf);
        $inhalt .= $this->kriterienKarte($csrf);
        $inhalt .= $this->ausschlussKarte($csrf);
        $inhalt .= $this->vorgabenKarte($csrf);
        // Das gemeinsame Suchfeld des Kerns (#341) statt einer achten eigenen
        // Kopie derselben AJAX-Suche - genau der Grund, aus dem es den
        // Endpunkt gibt.
        $inhalt .= '<script src="/js/horse-search.js"></script>';

        PluginPage::render('Pferd des Tages', $inhalt);
    }

    public function kriterien(): void {
        $this->pruefeCsrf();

        $vorher = Kriterien::laden();
        $kriterien = Kriterien::ausEingabe($_POST);

        // Ein Kür-Kriterium, dessen Feld gar nicht angezeigt wurde (das
        // zugehörige Addon fehlt gerade), behält seinen Wert. Sonst löschte
        // das Speichern der Kriterien still eine Einstellung, die niemand
        // angefasst hat - und nach dem Wiedereinspielen des Addons stünde
        // sie auf "aus", ohne dass das jemand entschieden hätte.
        foreach (Kriterien::KUER as $feld => $tabelle) {
            if (!Fremdtabelle::vorhanden($tabelle)) {
                $kriterien[$feld] = $vorher[$feld];
            }
        }

        Kriterien::speichern($kriterien);

        PluginAudit::log(
            Plugin::SLUG,
            'Kriterien geändert',
            'Addon-Einstellungen',
            'Nur mit Foto: ' . (!empty($kriterien['nur_mit_foto']) ? 'ja' : 'nein')
                . ', Verstorbene: ' . (!empty($kriterien['verstorbene_einschliessen']) ? 'einschliessen' : 'ausschliessen')
                . ', Schonfrist: ' . ((int) $kriterien['schonfrist_tage']) . ' Tage'
        );

        $this->zurueck('gespeichert');
    }

    /**
     * "Heute neu wählen": verwirft die Zeile für heute, die nächste Anzeige
     * trifft die Wahl neu.
     *
     * Das ist der Ausweg aus der Lage, die #135 beschreibt: Wird das Pferd
     * des Tages mittags depubliziert, fällt der Kasten weg - automatisch neu
     * zu wählen wäre die falsche Antwort, weil die Kandidatensuche dann für
     * den Rest des Tages bei JEDEM Aufruf der Startseite liefe. Ein Klick
     * hier kostet einmal, was sonst tausendmal kostete.
     */
    public function neuWaehlen(): void {
        $this->pruefeCsrf();

        Auswahl::verwerfen(Auswahl::heute());
        PluginAudit::log(Plugin::SLUG, 'Wahl des Tages verworfen', 'Tag ' . Auswahl::heute());

        $this->zurueck('neu-gewaehlt');
    }

    public function ausschlussHinzufuegen(): void {
        $this->pruefeCsrf();

        $horseId = self::pferdAusAnfrage($_POST['horse_id'] ?? null);
        if ($horseId === null) {
            $this->zurueck('kein-pferd');
        }

        $grund = mb_substr(trim((string) ($_POST['grund'] ?? '')), 0, 200);

        $stmt = Database::getInstance()->prepare(
            'INSERT INTO `plugin_pferd_des_tages_ausschluss` (horse_id, grund) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE grund = VALUES(grund)'
        );
        $stmt->execute([$horseId, $grund === '' ? null : $grund]);

        // Ein Ausschluss wirkt erst auf die nächste Wahl. Steht das Pferd
        // gerade heute auf der Startseite, wäre das genau der Fall, in dem
        // jemand den Ausschluss eintippt - also weg damit.
        $heute = Auswahl::heute();
        $aktuell = Auswahl::fuerTag($heute);
        if ($aktuell !== null && (int) $aktuell['id'] === $horseId) {
            Auswahl::verwerfen($heute);
        }

        PluginAudit::log(Plugin::SLUG, 'Pferd dauerhaft ausgenommen', 'Pferd ' . Auswahl::bezug($horseId));
        $this->zurueck('ausgeschlossen');
    }

    public function ausschlussEntfernen(): void {
        $this->pruefeCsrf();

        $horseId = self::pferdAusAnfrage($_POST['horse_id'] ?? null);
        if ($horseId === null) {
            $this->zurueck('kein-pferd');
        }

        $stmt = Database::getInstance()->prepare('DELETE FROM `plugin_pferd_des_tages_ausschluss` WHERE horse_id = ?');
        $stmt->execute([$horseId]);

        PluginAudit::log(Plugin::SLUG, 'Ausnahme aufgehoben', 'Pferd ' . Auswahl::bezug($horseId));
        $this->zurueck('ausschluss-weg');
    }

    public function vorgabeSetzen(): void {
        $this->pruefeCsrf();

        $datum = self::datumAusAnfrage($_POST['datum'] ?? null);
        if ($datum === null) {
            $this->zurueck('kein-datum');
        }

        $horseId = self::pferdAusAnfrage($_POST['horse_id'] ?? null);
        if ($horseId === null) {
            $this->zurueck('kein-pferd');
        }

        Auswahl::festlegen($datum, $horseId);

        PluginAudit::log(
            Plugin::SLUG,
            'Pferd des Tages redaktionell vorgegeben',
            'Pferd ' . Auswahl::bezug($horseId),
            'für ' . $datum
        );
        $this->zurueck('vorgabe-gesetzt');
    }

    public function vorgabeEntfernen(): void {
        $this->pruefeCsrf();

        $datum = self::datumAusAnfrage($_POST['datum'] ?? null);
        if ($datum === null) {
            $this->zurueck('kein-datum');
        }

        Auswahl::verwerfen($datum);

        PluginAudit::log(Plugin::SLUG, 'Vorgabe entfernt', 'Tag ' . $datum);
        $this->zurueck('vorgabe-weg');
    }

    // ------------------------------------------------------------------
    // Seitenteile
    // ------------------------------------------------------------------

    private function heuteKarte(string $csrf): string {
        $heute = Auswahl::heute();
        $pferd = Auswahl::fuerTag($heute);

        $html = '<div class="card"><h2 style="margin-top:0;">🐴 Heute</h2>';

        if ($pferd === null) {
            $html .= '<p style="color:var(--text-muted);">Für heute steht kein Pferd auf der Startseite. '
                . 'Entweder treffen die Kriterien nichts, oder das gewählte Pferd ist nicht mehr veröffentlicht. '
                . 'Auf der Startseite erscheint dann kein Kasten - kein leerer Rahmen.</p>';
        } else {
            $html .= '<p>Auf der Startseite steht heute <strong>'
                . self::esc($pferd['name']) . '</strong> '
                . '(<a href="/admin/horses/edit?id=' . (int) $pferd['id'] . '">bearbeiten</a>, '
                . '<a href="/horse?id=' . (int) $pferd['id'] . '">öffentliche Seite</a>).</p>';
        }

        $html .= '<form method="POST" action="' . Plugin::VERWALTUNG . '/neu-waehlen" style="margin:0;">'
            . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
            . '<button type="submit" class="btn btn-secondary">Heute neu wählen</button>'
            . ' <span style="color:var(--text-muted);font-size:0.85rem;">'
            . 'Verwirft die heutige Wahl; die nächste Anzeige trifft sie nach den aktuellen Kriterien neu.</span>'
            . '</form>';

        return $html . '</div>';
    }

    private function kriterienKarte(string $csrf): string {
        $k = Kriterien::laden();

        $html = '<div class="card"><h2 style="margin-top:0;">Kriterien</h2>';
        $html .= '<p style="color:var(--text-muted);">Aus welcher Menge wird gezogen? '
            . 'Nur veröffentlichte Pferde stehen ohnehin zur Wahl - das ist nicht verhandelbar '
            . 'und deshalb keine Einstellung.</p>';

        $html .= '<form method="POST" action="' . Plugin::VERWALTUNG . '/kriterien">';
        $html .= '<input type="hidden" name="csrf_token" value="' . $csrf . '">';

        $html .= '<div class="form-group">'
            . self::kontrollkasten('nur_mit_foto', 'Nur Pferde mit Foto', !empty($k['nur_mit_foto']))
            . '</div>';
        $html .= '<div class="form-group">'
            . self::kontrollkasten('verstorbene_einschliessen', 'Verstorbene Pferde einschliessen', !empty($k['verstorbene_einschliessen']))
            . '</div>';

        $html .= '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;">';
        $html .= self::auswahlFeld('status', 'Zuchtstatus', Kriterien::STATUS, (string) $k['status']);
        $html .= self::auswahlFeld('sex', 'Geschlecht', Kriterien::GESCHLECHTER, (string) $k['sex']);
        $html .= self::auswahlFeld('farbe', 'Farbe', self::werteliste('color'), (string) $k['farbe']);
        $html .= self::auswahlFeld('rasse', 'Rasse', self::werteliste('breed'), (string) $k['rasse']);
        $html .= self::auswahlFeld('station_id', 'Deckstation', self::kontaktliste('station'), (string) ($k['station_id'] ?? ''));
        $html .= self::auswahlFeld('zuechter_id', 'Züchter', self::kontaktliste('breeder'), (string) ($k['zuechter_id'] ?? ''));
        $html .= '<div class="form-group"><label for="pdt_jahr_von">Geburtsjahr von</label>'
            . '<input type="number" class="form-control" id="pdt_jahr_von" name="jahr_von"'
            . ' min="' . Kriterien::JAHR_MIN . '" max="' . Kriterien::JAHR_MAX . '"'
            . ' value="' . ($k['jahr_von'] !== null ? (int) $k['jahr_von'] : '') . '"></div>';
        $html .= '<div class="form-group"><label for="pdt_jahr_bis">Geburtsjahr bis</label>'
            . '<input type="number" class="form-control" id="pdt_jahr_bis" name="jahr_bis"'
            . ' min="' . Kriterien::JAHR_MIN . '" max="' . Kriterien::JAHR_MAX . '"'
            . ' value="' . ($k['jahr_bis'] !== null ? (int) $k['jahr_bis'] : '') . '"></div>';
        $html .= '<div class="form-group"><label for="pdt_schonfrist">Schonfrist in Tagen</label>'
            . '<input type="number" class="form-control" id="pdt_schonfrist" name="schonfrist_tage"'
            . ' min="0" max="' . Kriterien::MAX_SCHONFRIST . '" value="' . (int) $k['schonfrist_tage'] . '">'
            . '<small style="color:var(--text-muted);">Ein Pferd kommt innerhalb dieser Frist nicht wieder - '
            . 'ausser die Auswahl ist erschöpft, dann zählt sie für diesen Tag nicht. 0 schaltet sie ab.</small></div>';
        $html .= '</div>';

        $html .= self::kuerKarte($k);

        $html .= '<p style="margin-bottom:0;"><button type="submit" class="btn">Kriterien speichern</button></p>';
        $html .= '</form>';

        return $html . '</div>';
    }

    /**
     * Die Kür (#135): Kriterien, die auf Daten anderer Addons zugreifen. Sie
     * erscheinen nur, wenn das jeweilige Addon seine Tabelle angelegt hat -
     * eine Einstellung, die nichts bewirken kann, ist schlimmer als keine.
     *
     * @param array<string, mixed> $k
     */
    private function kuerKarte(array $k): string {
        $teile = [];

        if (Fremdtabelle::vorhanden(Kriterien::KUER['nur_ausgezeichnete'])) {
            $teile[] = self::kontrollkasten('nur_ausgezeichnete', 'Nur Pferde mit Auszeichnung (titel-praemierungen)', !empty($k['nur_ausgezeichnete']));
        }
        if (Fremdtabelle::vorhanden(Kriterien::KUER['nur_verkaeuflich'])) {
            $teile[] = self::kontrollkasten('nur_verkaeuflich', 'Nur Pferde mit aktivem Verkaufsinserat (verkaufsboerse)', !empty($k['nur_verkaeuflich']));
        }
        if (Fremdtabelle::vorhanden(Kriterien::KUER['min_aufrufe'])) {
            $teile[] = '<label for="pdt_min_aufrufe">Mindestens so viele Seitenaufrufe (statistik-dashboard)</label>'
                . '<input type="number" class="form-control" id="pdt_min_aufrufe" name="min_aufrufe"'
                . ' min="0" value="' . (int) ($k['min_aufrufe'] ?? 0) . '" style="max-width:12rem;">';
        }

        if ($teile === []) {
            return '';
        }

        return '<fieldset style="margin-top:1rem;border:1px solid var(--border-color);'
            . 'border-radius:var(--border-radius);padding:1rem;">'
            . '<legend style="padding:0 0.5rem;">Aus anderen Addons</legend>'
            . '<p style="margin-top:0;color:var(--text-muted);">Kür, nicht Voraussetzung: '
            . 'Wird das zugehörige Addon deinstalliert, fällt das Kriterium weg - '
            . 'das Pferd des Tages bleibt.</p>'
            . '<div class="form-group">' . implode('</div><div class="form-group">', $teile) . '</div>'
            . '</fieldset>';
    }

    private function ausschlussKarte(string $csrf): string {
        $stmt = Database::getInstance()->query(
            'SELECT a.horse_id, a.grund, h.name, h.deleted_at
             FROM `plugin_pferd_des_tages_ausschluss` a
             JOIN horses h ON h.id = a.horse_id
             ORDER BY h.name ASC'
        );
        $zeilen = $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC);

        $html = '<div class="card"><h2 style="margin-top:0;">Dauerhaft ausgenommen</h2>';
        $html .= '<p style="color:var(--text-muted);">Auf Wunsch des Besitzers, oder weil die Daten '
            . 'unvollständig sind. Ein ausgenommenes Pferd bleibt im Katalog sichtbar - es kommt nur '
            . 'nicht mehr als Pferd des Tages an die Reihe.</p>';

        if ($zeilen === []) {
            $html .= '<p style="color:var(--text-muted);">Kein Pferd ausgenommen.</p>';
        } else {
            $html .= '<div class="tabelle-scroll"><table style="width:100%;border-collapse:collapse;"><thead>'
                . '<tr style="text-align:left;border-bottom:2px solid var(--border-color);">'
                . '<th style="padding:0.4rem;">Pferd</th><th style="padding:0.4rem;">Grund</th>'
                . '<th style="padding:0.4rem;"></th></tr></thead><tbody>';
            foreach ($zeilen as $zeile) {
                $id = (int) $zeile['horse_id'];
                $html .= '<tr style="border-bottom:1px solid var(--border-color);">';
                $html .= '<td style="padding:0.4rem;"><a href="/admin/horses/edit?id=' . $id . '">'
                    . self::esc($zeile['name']) . '</a>'
                    . ($zeile['deleted_at'] !== null ? ' <span style="color:var(--text-muted);">(im Papierkorb)</span>' : '')
                    . '</td>';
                $html .= '<td style="padding:0.4rem;color:var(--text-muted);">'
                    . self::esc($zeile['grund'] ?? '–') . '</td>';
                $html .= '<td style="padding:0.4rem;text-align:right;">'
                    . '<form method="POST" action="' . Plugin::VERWALTUNG . '/ausschluss/entfernen" style="margin:0;">'
                    . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
                    . '<input type="hidden" name="horse_id" value="' . $id . '">'
                    . '<button type="submit" class="btn btn-secondary">Wieder zulassen</button></form></td>';
                $html .= '</tr>';
            }
            $html .= '</tbody></table></div>';
        }

        $html .= '<h3>Pferd ausnehmen</h3>';
        $html .= '<form method="POST" action="' . Plugin::VERWALTUNG . '/ausschluss">';
        $html .= '<input type="hidden" name="csrf_token" value="' . $csrf . '">';
        $html .= self::pferdesuche('pdt_ausschluss', 'Pferd suchen');
        $html .= '<div class="form-group"><label for="pdt_grund">Grund (optional, nur intern)</label>'
            . '<input type="text" class="form-control" id="pdt_grund" name="grund" maxlength="200"'
            . ' placeholder="z. B. auf Wunsch des Besitzers"></div>';
        $html .= '<p style="margin-bottom:0;"><button type="submit" class="btn">Ausnehmen</button></p>';
        $html .= '</form>';

        return $html . '</div>';
    }

    private function vorgabenKarte(string $csrf): string {
        $heute = Auswahl::heute();

        // Alles ab heute (die Vorgaben, die noch wirken) plus die letzten
        // Tage zur Kontrolle. Die Liste beantwortet die Frage, die nach zwei
        // Wochen aufkommt: "Welches Pferd stand wann da?"
        $stmt = Database::getInstance()->prepare(
            'SELECT w.datum, w.horse_id, w.fest, h.name, h.is_published
             FROM `plugin_pferd_des_tages_wahl` w
             LEFT JOIN horses h ON h.id = w.horse_id
             WHERE w.datum >= ? OR w.fest = 1
             ORDER BY w.datum DESC
             LIMIT ' . self::LETZTE_WAHLEN
        );
        $stmt->execute([date('Y-m-d', (int) strtotime($heute . ' -' . self::LETZTE_WAHLEN . ' days'))]);
        $zeilen = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $html = '<div class="card"><h2 style="margin-top:0;">Vorgaben und getroffene Wahlen</h2>';
        $html .= '<p style="color:var(--text-muted);">Für ein bestimmtes Datum lässt sich ein Pferd fest '
            . 'vorgeben - Fohlenschau, Jubiläum, Verbandstermin. Eine Vorgabe schlägt die automatische '
            . 'Wahl; die Kriterien gelten für sie nicht.</p>';

        if ($zeilen === []) {
            $html .= '<p style="color:var(--text-muted);">Noch keine Wahl getroffen.</p>';
        } else {
            $html .= '<div class="tabelle-scroll"><table style="width:100%;border-collapse:collapse;"><thead>'
                . '<tr style="text-align:left;border-bottom:2px solid var(--border-color);">'
                . '<th style="padding:0.4rem;">Datum</th><th style="padding:0.4rem;">Pferd</th>'
                . '<th style="padding:0.4rem;">Art</th><th style="padding:0.4rem;"></th></tr></thead><tbody>';
            foreach ($zeilen as $zeile) {
                $datum = (string) $zeile['datum'];
                $html .= '<tr style="border-bottom:1px solid var(--border-color);">';
                $html .= '<td style="padding:0.4rem;">' . self::esc($datum)
                    . ($datum === $heute ? ' <strong>(heute)</strong>' : '') . '</td>';
                if ($zeile['horse_id'] === null) {
                    $html .= '<td style="padding:0.4rem;color:var(--text-muted);">kein Kandidat</td>';
                } else {
                    $html .= '<td style="padding:0.4rem;"><a href="/admin/horses/edit?id=' . (int) $zeile['horse_id'] . '">'
                        . self::esc($zeile['name'] ?? ('#' . (int) $zeile['horse_id'])) . '</a>'
                        . (empty($zeile['is_published'])
                            ? ' <span style="color:var(--text-muted);">(nicht veröffentlicht - erscheint nicht)</span>'
                            : '')
                        . '</td>';
                }
                $html .= '<td style="padding:0.4rem;color:var(--text-muted);">'
                    . (!empty($zeile['fest']) ? 'Vorgabe' : 'automatisch') . '</td>';
                $html .= '<td style="padding:0.4rem;text-align:right;">'
                    . '<form method="POST" action="' . Plugin::VERWALTUNG . '/vorgabe/entfernen" style="margin:0;">'
                    . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
                    . '<input type="hidden" name="datum" value="' . self::esc($datum) . '">'
                    . '<button type="submit" class="btn btn-secondary">Aufheben</button></form></td>';
                $html .= '</tr>';
            }
            $html .= '</tbody></table></div>';
        }

        $html .= '<h3>Vorgabe setzen</h3>';
        $html .= '<form method="POST" action="' . Plugin::VERWALTUNG . '/vorgabe">';
        $html .= '<input type="hidden" name="csrf_token" value="' . $csrf . '">';
        $html .= '<div class="form-group"><label for="pdt_datum">Datum</label>'
            . '<input type="date" class="form-control" id="pdt_datum" name="datum"'
            . ' value="' . self::esc($heute) . '" style="max-width:14rem;"></div>';
        $html .= self::pferdesuche('pdt_vorgabe', 'Pferd suchen');
        $html .= '<p style="margin-bottom:0;"><button type="submit" class="btn">Vorgabe speichern</button></p>';
        $html .= '</form>';

        return $html . '</div>';
    }

    // ------------------------------------------------------------------
    // Bausteine
    // ------------------------------------------------------------------

    /**
     * Das gemeinsame Suchfeld des Kerns (#341): Textfeld mit der Klasse
     * `hv-pferdesuche`, das per data-ziel ein <select> befüllt. Kein eigener
     * AJAX-Block - genau die siebenfache Kopie, die #341 abgeschafft hat.
     */
    private static function pferdesuche(string $praefix, string $beschriftung): string {
        $zielId = $praefix . '_id';
        return '<div class="form-group"><label for="' . $praefix . '_q">' . self::esc($beschriftung) . '</label>'
            . '<input type="text" class="form-control hv-pferdesuche" id="' . $praefix . '_q"'
            . ' data-ziel="' . $zielId . '" placeholder="Name oder Lebensnummer, ab zwei Zeichen" autocomplete="off">'
            . '<select name="horse_id" id="' . $zielId . '" class="form-control" style="margin-top:0.5rem;">'
            . '<option value="">-- bitte zuerst suchen --</option></select></div>';
    }

    /** @param array<string, string> $optionen */
    private static function auswahlFeld(string $name, string $beschriftung, array $optionen, string $gewaehlt): string {
        $html = '<div class="form-group"><label for="pdt_' . $name . '">' . self::esc($beschriftung) . '</label>'
            . '<select class="form-control" id="pdt_' . $name . '" name="' . $name . '">'
            . '<option value="">— egal —</option>';

        // Ein gespeicherter Wert, den es im Bestand nicht mehr gibt (Farbe
        // umbenannt, Station gelöscht), bleibt in der Liste stehen. Sonst
        // stünde die Auswahl auf "egal", ohne dass jemand das getan hätte -
        // und der Betreiber wunderte sich über eine Menge, die er so nie
        // eingestellt hat.
        if ($gewaehlt !== '' && !isset($optionen[$gewaehlt])) {
            $optionen[$gewaehlt] = $gewaehlt . ' (nicht mehr im Bestand)';
        }

        foreach ($optionen as $wert => $label) {
            $html .= '<option value="' . self::esc($wert) . '"'
                . ((string) $wert === $gewaehlt ? ' selected' : '') . '>'
                . self::esc($label) . '</option>';
        }

        return $html . '</select></div>';
    }

    private static function kontrollkasten(string $name, string $beschriftung, bool $an): string {
        return '<label for="pdt_' . $name . '" style="display:flex;align-items:center;gap:0.5rem;">'
            . '<input type="checkbox" id="pdt_' . $name . '" name="' . $name . '" value="1"'
            . ($an ? ' checked' : '') . '> ' . self::esc($beschriftung) . '</label>';
    }

    /**
     * Vorhandene Werte einer Freitext-Spalte (`color`/`breed`) als Auswahl.
     * Beide Spalten sind indiziert (#221), das ist ein Index-Only-Scan.
     *
     * @return array<string, string>
     */
    private static function werteliste(string $spalte): array {
        // Der Spaltenname kommt NICHT aus der Anfrage - er ist einer von zwei
        // festen Werten. Die Prüfung steht trotzdem hier: Sie kostet nichts
        // und macht die Stelle gegen einen späteren Aufrufer sicher, der es
        // anders sieht.
        if (!in_array($spalte, ['color', 'breed'], true)) {
            return [];
        }

        try {
            $stmt = Database::getInstance()->query(
                'SELECT DISTINCT `' . $spalte . '` AS wert FROM horses
                 WHERE deleted_at IS NULL AND `' . $spalte . "` IS NOT NULL AND `" . $spalte . "` <> ''
                 ORDER BY wert ASC"
            );
            $werte = $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Throwable $e) {
            return [];
        }

        $liste = [];
        foreach ($werte as $wert) {
            $liste[(string) $wert] = (string) $wert;
        }
        return $liste;
    }

    /**
     * Kontakte, die tatsächlich als Deckstation bzw. Züchter vorkommen -
     * nicht der gesamte Kontaktbestand. Eine Auswahlliste mit tausend
     * Einträgen, von denen zwanzig treffen könnten, ist keine Hilfe.
     *
     * Geliefert wird nur der Name (Anzeigetext einer Auswahlliste im
     * Adminbereich); Kontaktfelder haben hier nichts zu suchen.
     *
     * @return array<string, string> Kontakt-ID => Name
     */
    private static function kontaktliste(string $art): array {
        $sql = $art === 'station'
            ? 'SELECT DISTINCT c.id, c.name FROM contacts c
               JOIN horses h ON h.breeding_station_id = c.id AND h.deleted_at IS NULL
               WHERE c.deleted_at IS NULL ORDER BY c.name ASC'
            : "SELECT DISTINCT c.id, c.name FROM contacts c
               JOIN horse_persons hp ON hp.contact_id = c.id AND hp.role = 'breeder'
               JOIN horses h ON h.id = hp.horse_id AND h.deleted_at IS NULL
               WHERE c.deleted_at IS NULL ORDER BY c.name ASC";

        try {
            $stmt = Database::getInstance()->query($sql);
            $zeilen = $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }

        $liste = [];
        foreach ($zeilen as $zeile) {
            $liste[(string) (int) $zeile['id']] = (string) $zeile['name'];
        }
        return $liste;
    }

    private static function meldung(): string {
        $schluessel = (string) ($_GET['meldung'] ?? '');
        if (!isset(self::MELDUNGEN[$schluessel])) {
            return '';
        }

        // Fehlermeldungen anders einfärben als Erfolgsmeldungen - beides über
        // Theme-Variablen, damit der Darkmode nicht bricht.
        $fehler = in_array($schluessel, ['kein-pferd', 'kein-datum'], true);
        return '<div class="card" style="border-left:4px solid var('
            . ($fehler ? '--danger-fg' : '--primary-color') . ');">'
            . '<p style="margin:0;">' . self::esc(self::MELDUNGEN[$schluessel]) . '</p></div>';
    }

    // ------------------------------------------------------------------
    // Eingabeprüfung
    // ------------------------------------------------------------------

    private static function pferdAusAnfrage(mixed $roh): ?int {
        $id = filter_var($roh, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($id)) {
            return null;
        }

        // Existenz prüfen, statt es dem Fremdschlüssel zu überlassen: Ein
        // erfundener Wert liefe sonst in eine PDOException und damit in eine
        // 500er-Seite, obwohl das schlicht eine ungültige Eingabe ist.
        $stmt = Database::getInstance()->prepare('SELECT 1 FROM horses WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetchColumn() !== false ? $id : null;
    }

    /**
     * Datum nur als echtes Kalenderdatum im Format JJJJ-MM-TT. checkdate()
     * zusätzlich zum Muster: "2026-02-31" passt auf das Muster und ist
     * trotzdem kein Tag.
     */
    private static function datumAusAnfrage(mixed $roh): ?string {
        $wert = trim((string) $roh);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $wert, $treffer)) {
            return null;
        }
        return checkdate((int) $treffer[2], (int) $treffer[3], (int) $treffer[1]) ? $wert : null;
    }

    private function pruefeCsrf(): void {
        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }
    }

    private static function esc(mixed $wert): string {
        return htmlspecialchars((string) $wert, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Rückweg. Bewusst KEINE übergebene URL, sondern eine feste Adresse plus
     * ein Schlüssel aus der eigenen Liste - eine mitgeschickte Zieladresse
     * wäre ein offener Redirect.
     */
    private function zurueck(string $meldung): never {
        header('Location: ' . Plugin::VERWALTUNG . '?meldung=' . urlencode($meldung));
        exit;
    }
}
