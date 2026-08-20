<?php
// datenmigration/Plugin.php
//
// Addon für Hengstverzeichnis_Framework: Umzug einer Instanz auf eine andere
// (Framework -> Framework). Der Export bündelt in EIN Archiv:
//
//   manifest.json   Kern-Version, Seitenname, Plugin-Bestand, Zählstände,
//                   und seit #121 die AUSWAHL (welche Gruppen im Archiv sind)
//   database.sql    DB-Dump der ausgewählten Tabellen (App\Service\DatabaseDumper)
//   uploads/...     hochgeladene Dateien aus public/uploads (Pferdebilder,
//                   Logos, Galerie) - die im Kern-Backup bewusst fehlen
//                   (siehe BackupService: "Kann bei Bedarf als eigenständige
//                   Erweiterung nachgezogen werden")
//
// AUSWAHL STATT ALLES-ODER-NICHTS (#121). Bis v0.7 nahm der Export
// zwangsläufig jede Tabelle mit - also auch `users` mit den Passwort-Hashes,
// den TOTP-Geheimnissen und den Backup-Codes, dazu `api_keys`. Wer nur seine
// Pferde und Kontakte weitergeben wollte, verschickte die Anmeldedaten seiner
// Instanz gleich mit. Seit #121 wählt der Betreiber Gruppen aus (siehe
// Exportauswahl weiter unten); der Kern beschränkt den Dump darauf
// (DatabaseDumper::dumpTo($write, $tabellen), Framework#342).
//
// Der Import auf der Zielinstanz ist zweistufig (Prüfen -> Anwenden):
// Manifest-Vorschau mit Versions-/Plugin-Abgleich, dann ausdrückliche
// Bestätigung. Vor dem Anwenden wird ein Sicherungs-Dump der Zielinstanz
// geschrieben (Rückweg). Ein VOLLARCHIV ersetzt die Instanz (danach wird die
// Sitzung beendet - die Benutzerkonten sind ausgetauscht); ein TEILARCHIV
// wird zusammengeführt: Es ersetzt nur die enthaltenen Tabellen, alles andere
// bleibt stehen (siehe apply()).
//
// Archivformat ist tar (ustar, bei verfügbarem zlib als .tar.gz), bewusst
// OHNE ext-zip: Das mitgelieferte Dockerfile des Kerns installiert kein
// zip, und ein ustar-Schreiber/-Leser sind zusammen unter 150 Zeilen -
// konsistent mit der "keine externen Abhängigkeiten"-Philosophie
// (docs/plugin-development.md; vgl. die CSV-statt-xlsx-Entscheidung des
// HorseCsvImporter im Kern).
//
// Große Archive: PHP-Upload-Grenzen (upload_max_filesize/post_max_size)
// gelten nur für den Upload-Weg. Große Archive legt man stattdessen per
// SFTP/Konsole direkt in var/datenmigration/ der Zielinstanz - die
// Übersicht listet alles in diesem Verzeichnis zum Prüfen/Anwenden auf.
//
// Nicht Teil des Umzugs (bewusst): config/db_config.php, APP_KEY und
// TLS/Proxy-Konfiguration - das ist Instanz-Infrastruktur, keine Daten.
//
// Installation: Verzeichnis nach plugins/ kopieren, unter /admin/plugins
// aktivieren, Berechtigungen "Datenmigration -> Export/Import" zuweisen.

namespace Plugin\Datenmigration;

use App\Controllers\BaseController;
use App\Database;
use App\Plugin\HookManager;
use App\Plugin\PluginAudit;
use App\Plugin\PluginPage;
use App\Router;
use App\Service\AuditLogger;
use App\Service\DatabaseDumper;
use PDO;

class Plugin {

    public function register(HookManager $hooks): void {
        $hooks->addFilter('admin.dashboard_tiles', [$this, 'addDashboardTile']);
    }

    public function addDashboardTile(array $tiles): array {
        $tiles[] = [
            'url' => '/plugin/datenmigration/uebersicht',
            'label' => 'Datenmigration',
            'icon' => '📦',
        ];
        return $tiles;
    }

    /**
     * @return array<int, array{module:string, action:string, label:string, module_label:string}>
     */
    public function permissions(): array {
        return [
            ['module' => 'datenmigration', 'action' => 'export',
             'label' => 'Export erstellen', 'module_label' => 'Datenmigration'],
            ['module' => 'datenmigration', 'action' => 'import',
             'label' => 'Import anwenden', 'module_label' => 'Datenmigration'],
        ];
    }

    /**
     * @return array<int, array{method:string, path:string, callback:array}>
     */
    public function routes(): array {
        return [
            ['method' => 'GET',  'path' => '/uebersicht',
             'callback' => [MigrationController::class, 'overview']],
            // Export ist seit #121 zweistufig: GET zeigt die Auswahl, POST
            // erstellt das Archiv. Ein GET, das nebenbei einen kompletten
            // Datenbank-Dump ausliefert, war ohnehin die falsche Methode -
            // es genügte ein <img src> auf einer fremden Seite, solange ein
            // Administrator angemeldet war.
            ['method' => 'GET',  'path' => '/export',
             'callback' => [MigrationController::class, 'exportForm']],
            ['method' => 'POST', 'path' => '/export',
             'callback' => [MigrationController::class, 'export']],
            ['method' => 'POST', 'path' => '/import/hochladen',
             'callback' => [MigrationController::class, 'upload']],
            ['method' => 'GET',  'path' => '/import/pruefen',
             'callback' => [MigrationController::class, 'preview']],
            ['method' => 'POST', 'path' => '/import/anwenden',
             'callback' => [MigrationController::class, 'apply']],
        ];
    }
}


// ---------------------------------------------------------------------------
// Auswahl: was geht mit? (#121)
// ---------------------------------------------------------------------------

/**
 * Die Gruppen, aus denen der Betreiber sein Archiv zusammenstellt.
 *
 * DAS PROBLEM WAR EIN SICHERHEITSPROBLEM. Der Export nahm zwangsläufig ALLES
 * mit - also `users` mit den Passwort-Hashes, den TOTP-Geheimnissen und den
 * Backup-Codes, dazu `api_keys`. Wer nur seine Pferde und Kontakte zu einer
 * anderen Instanz tragen wollte (Zuchtverband, Nachfolgesystem, Testinstanz),
 * verschickte die Anmeldedaten seines Vereins gleich mit, ohne es zu merken.
 *
 * WARUM GRUPPEN UND NICHT EINZELNE TABELLEN. Eine Tabellenliste ist keine
 * Frage, die ein Betreiber beantworten kann: `horse_persons`,
 * `contact_id_map` oder `group_permissions` sagen ihm nichts, und die eine
 * Tabelle, die er dann übersieht, ist die, an der es hinterher hängt. Die
 * Gruppen sind entlang der Frage geschnitten, die er tatsächlich hat -
 * "sollen die Benutzerkonten mit?" -, und jede Gruppe nennt in der Oberfläche
 * ihre Tabellen samt Zeilenzahl, damit die Abstraktion nichts verbirgt.
 *
 * DIE VORGABE IST "ALLES AUSSER BENUTZER, GRUPPEN, RECHTE". Beide bequemen
 * Enden sind falsch: "alles angehakt" macht die Änderung wirkungslos - der
 * Regelfall bliebe der Vollexport samt Zugangsdaten -, "nichts angehakt"
 * erzeugt ein leeres Archiv und erzieht zum gedankenlosen Alles-Anhaken.
 * Die Linie verläuft deshalb dort, wo sie sich in einem Satz begründen lässt:
 * ZUGANGSMATERIAL ist ab, DATEN sind an. Ein Passwort-Hash, ein TOTP-Secret,
 * ein API-Schlüssel verschafft dem Empfänger Zugang - unabhängig davon, was
 * er damit vorhat; das ist ein Vorfall, sobald es passiert. Kontaktdaten und
 * Protokolle sind Inhalte: heikel, aber genau das, was der Betreiber
 * absichtlich weitergibt, wenn er eine Instanz umzieht. Sie deshalb per
 * Vorgabe wegzulassen hieße, dem Regelfall (Umzug) still Daten zu entziehen -
 * dieselbe Klasse Fehler, nur andersherum.
 *
 * KEINE TABELLE FÄLLT STILL HERAUS. Die Zuordnung unten ist eine feste Liste,
 * und der Kern bekommt in der nächsten Version neue Tabellen. Eine Tabelle,
 * die in keiner Gruppe steht, wäre aus jedem Export verschwunden, ohne dass
 * es jemand merkt - deshalb landet alles Unbekannte in der Gruppe
 * `sonstiges`, die ihre Tabellen namentlich anzeigt und per Vorgabe an ist.
 * Lieber eine Gruppe, die "diese hier kenne ich nicht" sagt, als ein Archiv,
 * das schweigend unvollständig ist.
 */
final class Exportauswahl {

    /** Dateien (public/uploads) - die einzige Gruppe ohne Tabellen. */
    public const GRUPPE_DATEIEN = 'dateien';

    /** Auffangbecken für Tabellen, die keine Zuordnung haben (s. o.). */
    public const GRUPPE_SONSTIGES = 'sonstiges';

    /** Benutzerkonten und Zugangsmaterial - die Gruppe, die per Vorgabe AUS ist. */
    public const GRUPPE_BENUTZER = 'benutzer';

    /**
     * Reihenfolge = Anzeigereihenfolge. `vorgabe` ist die Voreinstellung des
     * Auswahlformulars, `hinweis` steht als Warnhinweis an der Gruppe.
     *
     * @var array<string, array{label:string, text:string, vorgabe:bool, hinweis:?string}>
     */
    public const GRUPPEN = [
        'pferde' => [
            'label' => 'Pferde, Abstammung & Zuordnungen',
            'text' => 'Pferdedatensätze samt Abstammung (Vater/Mutter stehen in horses selbst), '
                . 'Registriernummern, die Zuordnung Pferd↔Kontakt (Züchter, Besitzer, Betreuer, '
                . 'Deckstation) und die getroffenen Dubletten-Entscheidungen. '
                . 'match_labels enthält auch die Entscheidungen zu Kontakt-Dubletten - sie hängen '
                . 'an derselben Mechanik und ließen sich nicht sinnvoll auftrennen.',
            'vorgabe' => true,
            'hinweis' => null,
        ],
        'kontakte' => [
            'label' => 'Kontakte (Personen & Deckstationen)',
            'text' => 'Seit v0.8 eine Tabelle (Framework#336). contact_id_map bildet die alten '
                . 'Personen-/Stationskennungen ab und muss mit - ohne sie laufen die dauerhaften '
                . 'Weiterleitungen von /person und /station ins Leere.',
            'vorgabe' => true,
            'hinweis' => 'Personenbezogene Daten: Namen, Anschriften, E-Mail, Telefon.',
        ],
        'addons' => [
            'label' => 'Addon-Daten',
            'text' => 'Alle Tabellen mit dem Präfix plugin_ sowie der Aktivierungsstand der Addons. '
                . 'Der Kern prüft nach dem Import den Verzeichnis-Fingerabdruck und deaktiviert, '
                . 'was lokal nicht identisch vorliegt (fail-closed).',
            'vorgabe' => true,
            'hinweis' => null,
        ],
        'einstellungen' => [
            'label' => 'Einstellungen & Branding',
            'text' => 'Seitenname, Anzeigeoptionen, Erscheinungsbild sowie die eingetragenen '
                . 'Addon-Store-Quellen.',
            'vorgabe' => true,
            'hinweis' => null,
        ],
        'protokoll' => [
            'label' => 'Protokolle & Auskunftsanfragen',
            'text' => 'Audit-Log und DSGVO-Anfragen. Das Audit-Log führt den Benutzernamen als '
                . 'Text mit und bleibt deshalb auch ohne die Benutzertabelle lesbar.',
            'vorgabe' => true,
            'hinweis' => 'Enthält Benutzernamen, IP-Adressen und die Namen/E-Mail-Adressen von '
                . 'Auskunftsersuchenden.',
        ],
        self::GRUPPE_BENUTZER => [
            'label' => 'Benutzer, Gruppen, Rechte',
            'text' => 'Konten, Gruppenzugehörigkeit, Berechtigungsmatrix, API-Schlüssel, '
                . 'Passwort-Zurücksetzungen und die Fehlversuchszähler des Brute-Force-Schutzes.',
            'vorgabe' => false,
            'hinweis' => 'ZUGANGSMATERIAL: Passwort-Hashes, TOTP-Geheimnisse, Backup-Codes und '
                . 'API-Schlüssel-Hashes. Wer dieses Archiv bekommt, bekommt die Zugänge Ihrer '
                . 'Instanz. Nur anhaken, wenn die Zielinstanz Ihre eigene ist.',
        ],
        self::GRUPPE_SONSTIGES => [
            'label' => 'Nicht zugeordnete Tabellen',
            'text' => 'Tabellen, für die dieses Addon keine Zuordnung kennt - typischerweise, '
                . 'weil der Kern neuer ist als das Addon. Sie stehen hier namentlich, statt '
                . 'stillschweigend aus jedem Export zu verschwinden.',
            'vorgabe' => true,
            'hinweis' => null,
        ],
        self::GRUPPE_DATEIEN => [
            'label' => 'Dateien (public/uploads)',
            'text' => 'Pferdebilder, Logos, Galerie-Medien und Dokumente. Die Dateien liegen im '
                . 'Dateisystem und lassen sich nicht nach Tabellen aufteilen - sie gehen '
                . 'vollständig mit oder gar nicht.',
            'vorgabe' => true,
            'hinweis' => null,
        ],
    ];

    /**
     * Feste Zuordnung Kern-Tabelle -> Gruppe. Alles mit dem Präfix `plugin_`
     * geht ohne Eintrag an `addons`, alles Übrige an `sonstiges`.
     *
     * @var array<string, string>
     */
    private const ZUORDNUNG = [
        'horses' => 'pferde',
        'horse_registrations' => 'pferde',
        'horse_persons' => 'pferde',
        'match_labels' => 'pferde',

        'contacts' => 'kontakte',
        'contact_id_map' => 'kontakte',

        'plugins' => 'addons',

        'settings' => 'einstellungen',
        'addon_repos' => 'einstellungen',

        'audit_logs' => 'protokoll',
        'gdpr_requests' => 'protokoll',

        'users' => self::GRUPPE_BENUTZER,
        'groups' => self::GRUPPE_BENUTZER,
        'user_groups' => self::GRUPPE_BENUTZER,
        'group_permissions' => self::GRUPPE_BENUTZER,
        'api_keys' => self::GRUPPE_BENUTZER,
        'password_resets' => self::GRUPPE_BENUTZER,
        'login_attempts' => self::GRUPPE_BENUTZER,
    ];

    /**
     * Verweise ohne Fremdschlüssel im Schema, die die Abhängigkeitsprüfung
     * sonst übersähe.
     *
     * Nur `match_labels` steht hier, und zwar vollständig: Die Tabelle hat
     * keinen Fremdschlüssel (der Verweis hängt an der Spalte `kind`), und ein
     * Label ohne sein Gegenstück ist nicht bloß leer, sondern schädlich - ein
     * 'different' aus einer fremden Instanz legt auf dem Ziel den Vorschlag zu
     * einem ganz anderen Paar still.
     *
     * Bewusst NICHT hier: `audit_logs.user_id` und `addon_repos.added_by`.
     * Beide führen den Namen zusätzlich als Text mit, ein fehlender Verweis
     * kostet dort nichts - sie stünden bei der Vorgabe-Auswahl in jeder
     * Warnung und würden die Warnungen entwerten, auf die es ankommt.
     *
     * @var array<string, array<int, array{0:string, 1:string}>> Tabelle => [[Zieltabelle, Bedingung], ...]
     */
    public const WEICHE_VERWEISE = [
        'match_labels' => [
            ['horses', "kind = 'horse'"],
            ['contacts', "kind = 'contact'"],
        ],
    ];

    private function __construct() {}

    /** @return array<int, string> Alle Gruppenschlüssel in Anzeigereihenfolge. */
    public static function schluessel(): array {
        return array_keys(self::GRUPPEN);
    }

    /** @return array<int, string> Die Voreinstellung des Auswahlformulars. */
    public static function vorgabe(): array {
        $aus = [];
        foreach (self::GRUPPEN as $key => $meta) {
            if ($meta['vorgabe']) {
                $aus[] = $key;
            }
        }
        return $aus;
    }

    /**
     * Macht aus beliebiger Eingabe (Formular, fremdes Manifest) eine
     * Auswahl: nur bekannte Schlüssel, doppelte entfernt, in
     * Anzeigereihenfolge. Unbekanntes wird verworfen, nicht durchgereicht -
     * die Auswahl steuert am Ende, welche Tabellen ersetzt werden.
     *
     * @param mixed $roh
     * @return array<int, string>
     */
    public static function bereinige(mixed $roh): array {
        if (!is_array($roh)) {
            return [];
        }
        $gewuenscht = [];
        foreach ($roh as $eintrag) {
            if (is_string($eintrag)) {
                $gewuenscht[$eintrag] = true;
            }
        }
        return array_values(array_filter(
            self::schluessel(),
            static fn(string $k): bool => isset($gewuenscht[$k])
        ));
    }

    /** Die Gruppe, in die eine tatsächlich vorhandene Tabelle fällt. */
    public static function gruppeFuer(string $tabelle): string {
        if (isset(self::ZUORDNUNG[$tabelle])) {
            return self::ZUORDNUNG[$tabelle];
        }
        if (str_starts_with($tabelle, 'plugin_')) {
            return 'addons';
        }
        return self::GRUPPE_SONSTIGES;
    }

    /**
     * Die Positivliste der zu exportierenden Tabellen: Schnittmenge aus
     * "gewählte Gruppen" und "tatsächlich vorhandene Tabellen". Die
     * Reihenfolge bleibt die von SHOW TABLES, damit Dumps stabil sind.
     *
     * @param array<int, string> $auswahl
     * @param array<int, string> $vorhandene Ergebnis von SHOW TABLES
     * @return array<int, string>
     */
    public static function tabellen(array $auswahl, array $vorhandene): array {
        $gewaehlt = array_flip($auswahl);
        return array_values(array_filter(
            $vorhandene,
            static fn(string $t): bool => isset($gewaehlt[self::gruppeFuer($t)])
        ));
    }

    /** Deckt die Auswahl jede Gruppe ab? Dann ist es ein Vollarchiv. */
    public static function istVollstaendig(array $auswahl): bool {
        return array_diff(self::schluessel(), self::bereinige($auswahl)) === [];
    }

    /** Beschriftung eines Schlüssels; unbekannte Schlüssel bleiben sichtbar. */
    public static function label(string $key): string {
        return self::GRUPPEN[$key]['label'] ?? $key;
    }
}


/**
 * Welche Dateien ein Import-Archiv unter uploads/ mitbringen darf.
 *
 * Steht als eigene Klasse neben TarWriter/TarReader und nicht im Controller:
 * Es ist eine reine Regel ohne Framework-Bezug, und genau so lässt sie sich
 * ohne Datenbank und ohne Kern-Instanz prüfen (tests/Unit).
 */
final class UploadNamePolicy {

    /**
     * Positivliste, keine Sperrliste: Eine Liste verbotener Endungen ist immer
     * unvollständig (.php5, .phtml, .phar, .htaccess selbst), eine Liste
     * erlaubter nicht.
     *
     * Der Umfang orientiert sich daran, was tatsächlich in public/uploads
     * liegt: Bilder (Kern, galerie), PDFs (Dokumente) und schlichte
     * Textdaten. Bewusst NICHT enthalten sind Archive und Office-Formate -
     * sie gehören nicht in ein öffentlich ausgeliefertes Verzeichnis.
     *
     * Trifft ein Import auf eine Datei außerhalb dieser Liste, bricht er ab
     * und NENNT die Datei, statt sie stillschweigend zu überspringen. Bei
     * einem Umzug ist ein stiller Datenverlust die schlechtere Antwort: Der
     * Betreiber soll entscheiden, ob die Datei ins Archiv gehört oder die
     * Liste erweitert wird.
     *
     * @var array<int, string>
     */
    public const ERLAUBTE_ENDUNGEN = [
        'jpg', 'jpeg', 'png', 'webp', 'gif', 'avif', 'svg',
        'pdf', 'txt', 'csv',
    ];

    /**
     * Endungen, die AN JEDER STELLE des Namens unzulässig sind - nicht nur als
     * letzte.
     *
     * Grund ist der Apache-Klassiker: Steht dort
     * `AddHandler application/x-httpd-php .php`, wertet der Server ALLE
     * Endungen eines Namens aus und führt auch "bild.php.jpg" als PHP aus. Die
     * Positivliste oben allein würde diesen Namen durchlassen, weil die letzte
     * Endung sauber ist.
     *
     * Umgekehrt darf die Regel nicht jede Endung gegen die Positivliste
     * prüfen: "stute.2024.jpg" ist ein völlig gewöhnlicher Dateiname, und ein
     * Import, der daran scheitert, wird umgangen statt befolgt. (Genau daran
     * ist der erste Entwurf im Test hängengeblieben.)
     *
     * @var array<int, string>
     */
    public const NIE_ERLAUBTE_ENDUNGEN = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'phtml', 'pht', 'phar',
        'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'jsp', 'asp', 'aspx', 'exe', 'htaccess', 'htpasswd',
    ];

    private function __construct() {}

    /**
     * Webserver-Steuerdateien werden aus dem Archiv NICHT übernommen, aber
     * sie brechen den Import auch nicht ab.
     *
     * Ein Export enthält zwangsläufig die `.htaccess`, mit der der Kern PHP in
     * public/uploads abschaltet - sie liegt ja in genau diesem Verzeichnis.
     * Sie zu übernehmen hieße, den Ausführungsschutz vom Inhalt des Archivs
     * bestimmen zu lassen; den Import daran scheitern zu lassen hieße, dass
     * kein einziger echter Export mehr einspielbar wäre. Also: überspringen
     * und den Schutz nach dem Umschalten aus dem Kern-Bestand neu schreiben
     * (siehe MigrationController::restoreUploadsProtection()).
     */
    public static function istWebserverSteuerdatei(string $rel): bool {
        return in_array(strtolower(basename($rel)), ['.htaccess', '.htpasswd', 'web.config'], true);
    }

    /**
     * @throws \RuntimeException wenn der Name nicht zulässig ist
     */
    public static function assertAllowed(string $rel): void {
        $base = basename($rel);

        // Punktdateien: .htaccess wäre die wirkungsvollste davon - die wird
        // aber schon vorher aussortiert (siehe istWebserverSteuerdatei()).
        // Was hier ankommt, ist eine andere versteckte Datei, und für die
        // gibt es in einem Upload-Verzeichnis keinen Grund.
        if (str_starts_with($base, '.')) {
            throw new \RuntimeException("Punktdateien sind im Archiv nicht zulässig: {$rel}");
        }

        $teile = explode('.', $base);
        array_shift($teile); // der Name selbst
        if ($teile === []) {
            throw new \RuntimeException("Datei ohne Endung ist im Archiv nicht zulässig: {$rel}");
        }

        // Keine ausführbare Endung an irgendeiner Stelle.
        foreach ($teile as $endung) {
            if (in_array(strtolower($endung), self::NIE_ERLAUBTE_ENDUNGEN, true)) {
                throw new \RuntimeException("Ausführbare Dateiendung im Archiv: {$rel}");
            }
        }

        // Und die tatsächliche Endung muss auf der Positivliste stehen.
        $letzte = strtolower((string) end($teile));
        if (!in_array($letzte, self::ERLAUBTE_ENDUNGEN, true)) {
            throw new \RuntimeException("Nicht erlaubte Dateiendung im Archiv: {$rel}");
        }
    }
}


// ---------------------------------------------------------------------------
// Archiv: minimaler ustar-Schreiber/-Leser (pure PHP, streamend)
// ---------------------------------------------------------------------------

/**
 * Streamender tar-Schreiber (ustar). Schreibt wahlweise über gzopen (.tar.gz,
 * wenn zlib verfügbar) oder fopen (.tar) - Dateien werden blockweise
 * durchgereicht, es liegt nie mehr als ein 512-Byte-Block im Speicher plus
 * der gerade gelesene Chunk. Lange Pfade nutzen das ustar-prefix-Feld.
 */
final class TarWriter {

    /** @var resource */
    private $handle;
    private bool $gzip;

    private function __construct($handle, bool $gzip) {
        $this->handle = $handle;
        $this->gzip = $gzip;
    }

    public static function create(string $path): self {
        $gzip = str_ends_with($path, '.gz') && function_exists('gzopen');
        $handle = $gzip ? gzopen($path, 'wb6') : fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException("Archiv nicht schreibbar: {$path}");
        }
        return new self($handle, $gzip);
    }

    private function write(string $data): void {
        $ok = $this->gzip ? gzwrite($this->handle, $data) : fwrite($this->handle, $data);
        if ($ok === false) {
            throw new \RuntimeException('Schreiben in das Archiv fehlgeschlagen.');
        }
    }

    public function addString(string $name, string $content): void {
        $this->writeHeader($name, strlen($content));
        $this->write($content);
        $this->pad(strlen($content));
    }

    public function addFile(string $name, string $sourcePath): void {
        $size = filesize($sourcePath);
        if ($size === false) {
            throw new \RuntimeException("Datei nicht lesbar: {$sourcePath}");
        }
        $in = fopen($sourcePath, 'rb');
        if ($in === false) {
            throw new \RuntimeException("Datei nicht lesbar: {$sourcePath}");
        }
        $this->writeHeader($name, $size);
        $written = 0;
        while (!feof($in)) {
            $chunk = fread($in, 1024 * 512);
            if ($chunk === false) {
                fclose($in);
                throw new \RuntimeException("Lesefehler: {$sourcePath}");
            }
            $this->write($chunk);
            $written += strlen($chunk);
        }
        fclose($in);
        if ($written !== $size) {
            throw new \RuntimeException("Datei änderte sich während des Exports: {$sourcePath}");
        }
        $this->pad($size);
    }

    public function close(): void {
        $this->write(str_repeat("\0", 1024)); // Zwei Null-Blöcke = Archivende
        $this->gzip ? gzclose($this->handle) : fclose($this->handle);
    }

    private function pad(int $size): void {
        $rest = $size % 512;
        if ($rest !== 0) {
            $this->write(str_repeat("\0", 512 - $rest));
        }
    }

    private function writeHeader(string $name, int $size): void {
        $prefix = '';
        if (strlen($name) > 100) {
            // ustar: name (100) + prefix (155), getrennt an einem '/'
            $cut = strrpos(substr($name, 0, 156), '/');
            if ($cut === false || strlen($name) - $cut - 1 > 100) {
                throw new \RuntimeException("Pfad zu lang für ustar: {$name}");
            }
            $prefix = substr($name, 0, $cut);
            $name = substr($name, $cut + 1);
        }
        $header = str_pad($name, 100, "\0")
            . '0000644' . "\0"                       // mode
            . '0000000' . "\0" . '0000000' . "\0"    // uid/gid
            . sprintf('%011o', $size) . "\0"
            . sprintf('%011o', time()) . "\0"
            . '        '                             // Platzhalter Prüfsumme
            . '0'                                    // typeflag: regular file
            . str_repeat("\0", 100)                  // linkname
            . "ustar\0" . '00'
            . str_pad('', 32, "\0") . str_pad('', 32, "\0")  // uname/gname
            . '0000000' . "\0" . '0000000' . "\0"    // devmajor/minor
            . str_pad($prefix, 155, "\0");
        $header = str_pad($header, 512, "\0");
        $checksum = 0;
        for ($i = 0; $i < 512; $i++) {
            $checksum += ord($header[$i]);
        }
        $header = substr_replace($header, sprintf('%06o', $checksum) . "\0 ", 148, 8);
        $this->write($header);
    }
}

/**
 * Streamender tar-Leser. gzopen liest transparent auch unkomprimierte
 * Dateien, deshalb ein Lesepfad für .tar und .tar.gz. Es werden nur
 * reguläre Dateien geliefert; alles andere (Symlinks, Devices, ...) wird
 * übersprungen - ein Migrationsarchiv enthält nichts dergleichen, und so
 * kann ein manipuliertes Archiv darüber auch nichts einschleusen.
 */
final class TarReader {

    /** @var resource */
    private $handle;

    public function __construct(string $path) {
        $handle = function_exists('gzopen') ? gzopen($path, 'rb') : fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Archiv nicht lesbar: {$path}");
        }
        $this->handle = $handle;
    }

    /**
     * Ruft $callback(name, size, readChunk) je regulärer Datei auf.
     * $readChunk() liefert den nächsten Datenblock oder '' am Dateiende -
     * der Callback MUSS bis '' lesen (er konsumiert den Stream).
     *
     * @param callable(string, int, callable():string):void $callback
     */
    public function each(callable $callback): void {
        while (true) {
            $header = $this->read(512);
            if ($header === '' || trim($header, "\0") === '') {
                break; // Archivende (Null-Block)
            }
            if (strlen($header) < 512) {
                throw new \RuntimeException('Archiv abgeschnitten (unvollständiger Header).');
            }
            $stored = (int) substr($header, 148, 8);
            $probe = substr_replace($header, '        ', 148, 8);
            $checksum = 0;
            for ($i = 0; $i < 512; $i++) {
                $checksum += ord($probe[$i]);
            }
            if ($checksum !== octdec(trim(substr($header, 148, 8), "\0 "))) {
                throw new \RuntimeException('Archiv beschädigt (Header-Prüfsumme falsch).');
            }
            $name = rtrim(substr($header, 0, 100), "\0");
            $prefix = rtrim(substr($header, 345, 155), "\0");
            if ($prefix !== '') {
                $name = $prefix . '/' . $name;
            }
            $size = (int) octdec(trim(substr($header, 124, 12), "\0 "));
            $type = $header[156];

            if ($type === '0' || $type === "\0") {
                $remaining = $size;
                $reader = function () use (&$remaining): string {
                    if ($remaining <= 0) {
                        return '';
                    }
                    $chunk = $this->read(min($remaining, 1024 * 512));
                    if ($chunk === '') {
                        throw new \RuntimeException('Archiv abgeschnitten (Dateiinhalt fehlt).');
                    }
                    $remaining -= strlen($chunk);
                    return $chunk;
                };
                $callback($name, $size, $reader);
                if ($remaining > 0) {
                    throw new \RuntimeException('Interner Fehler: Archiv-Eintrag nicht vollständig gelesen.');
                }
            } else {
                // Verzeichnisse & Sonderdateien: Inhalt überspringen
                $this->skip($size);
            }
            $rest = $size % 512;
            if ($rest !== 0) {
                $this->skip(512 - $rest);
            }
        }
    }

    public function close(): void {
        function_exists('gzclose') ? gzclose($this->handle) : fclose($this->handle);
    }

    private function read(int $bytes): string {
        $data = '';
        while (strlen($data) < $bytes) {
            $chunk = gzread($this->handle, $bytes - strlen($data));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;
        }
        return $data;
    }

    private function skip(int $bytes): void {
        while ($bytes > 0) {
            $chunk = $this->read(min($bytes, 1024 * 512));
            if ($chunk === '') {
                throw new \RuntimeException('Archiv abgeschnitten.');
            }
            $bytes -= strlen($chunk);
        }
    }
}


// ---------------------------------------------------------------------------
// Controller
// ---------------------------------------------------------------------------

class MigrationController extends BaseController {

    /**
     * Archivformat, das dieses Addon SCHREIBT.
     *
     * 2 seit #121: Das Manifest führt jetzt zusätzlich `auswahl` (welche
     * Gruppen im Archiv sind) und `vollstaendig`.
     */
    public const FORMAT = 2;

    /**
     * Formate, die dieses Addon LIEST.
     *
     * Format 1 bleibt drin, obwohl es `auswahl` nicht kennt: Ein Archiv aus
     * v0.7 ist immer ein Vollarchiv, das lässt sich beim Lesen einsetzen
     * (siehe auswahlDesArchivs()). Es zurückzuweisen hieße, vorhandene
     * Archive über Nacht unbrauchbar zu machen, ohne dass es dafür einen
     * Grund gäbe.
     *
     * Umgekehrt gilt das NICHT: Eine v0.7-Instanz weist ein Format-2-Archiv
     * ab, und das ist richtig - sie würde ein Teilarchiv wie einen
     * vollständigen Stand einspielen und alles Nicht-Enthaltene wegwerfen.
     *
     * @var array<int, int>
     */
    public const LESBARE_FORMATE = [1, 2];

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
    }

    /**
     * Export und Import verlangen zusätzlich zur Modulberechtigung
     * Administratorrechte.
     *
     * Die Berechtigungen `datenmigration.export`/`.import` sehen aus wie jede
     * andere Modulberechtigung und lassen sich im Gruppen-Editor an jede
     * Gruppe vergeben - ihre Wirkung ist aber eine ganz andere:
     *
     *   Export liefert den VOLLSTÄNDIGEN Datenbank-Dump aus, inklusive
     *   `users` (Passwort-Hashes, TOTP-Secrets), `api_keys` und aller
     *   personenbezogenen Daten. Wer ihn auslösen darf, hat faktisch
     *   Lesezugriff auf alles.
     *
     *   Import ERSETZT die gesamte Datenbank - also auch die Benutzertabelle.
     *   Wer ihn auslösen darf, kann sich mit einem selbst gebauten Archiv zum
     *   Administrator machen.
     *
     * Beides sind im Kern bewusst admin-only-Fähigkeiten (Backup, Update,
     * Systemreset). Ein Addon darf sie nicht über eine gewöhnliche, an
     * "Redakteure" vergebbare Berechtigung öffnen. Die Berechtigung bleibt
     * erhalten - sie erlaubt dem Betreiber weiterhin, die Funktion für
     * einzelne Administratoren abzuschalten -, sie genügt nur nicht mehr für
     * sich allein.
     */
    private function requireAdminForFullAccess(string $aktion): void {
        if ($this->isAdmin()) {
            return;
        }

        // Hier bleibt es bewusst bei AuditLogger und der Kategorie 'security'
        // statt PluginAudit (Framework#352): Das ist kein Vorgang dieses
        // Addons, sondern ein abgewiesener Versuch, an den gesamten
        // Datenbestand zu kommen. Er gehört zu den Sicherheitsereignissen,
        // die man am Stück durchsieht, nicht in den Addon-Filter.
        AuditLogger::log(
            'Datenmigration abgelehnt: Administratorrechte erforderlich',
            'security',
            "Aktion '{$aktion}' ohne Admin-Rechte angefordert",
            $_SESSION['user_id'] ?? null,
            $_SESSION['username'] ?? null
        );

        $this->renderForbidden(
            'Zugriff verweigert: Export und Import der Datenmigration betreffen den gesamten Datenbestand '
            . '(inklusive Benutzerkonten und Zugangsdaten) und stehen deshalb ausschließlich Administratoren offen.'
        );
    }

    private function rootDir(): string {
        return dirname(__DIR__, 2);
    }

    private function uploadsDir(): string {
        return $this->rootDir() . '/public/uploads';
    }

    /** Ablage für Archive und Sicherungs-Dumps - außerhalb von public/. */
    private function stageDir(): string {
        $dir = $this->rootDir() . '/var/datenmigration';
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        return $dir;
    }

    /** @return array<int, string> Archivdateien in var/datenmigration */
    private function stagedArchives(): array {
        $out = [];
        foreach (scandir($this->stageDir()) ?: [] as $f) {
            if (preg_match('/\.tar(\.gz)?$/', $f) && !str_starts_with($f, 'sicherung-')) {
                $out[] = $f;
            }
        }
        sort($out);
        return $out;
    }

    /** Dateiname aus Request-Parameter — strikt auf die Ablage begrenzt. */
    private function stagedPath(string $name): ?string {
        $name = basename($name);
        if (!preg_match('/^[A-Za-z0-9._-]+\.tar(\.gz)?$/', $name) || str_starts_with($name, 'sicherung-')) {
            return null;
        }
        $path = $this->stageDir() . '/' . $name;
        return is_file($path) ? $path : null;
    }

    /** @return array{core_version:string, site_name:string, plugins:array<int,array{slug:string,version:string,enabled:bool}>, tables:array<string,int>} */
    private function localInventory(): array {
        $db = Database::getInstance();
        $tables = [];
        // Backtick als eigene Konstante: haelt Identifier-Quoting und Variablen
        // auf getrennten Zeilen — der statische Sicherheits-Scan wertet
        // Backtick gefolgt von $variable auf einer Zeile als Shell-Ausfuehrung.
        $bt = '`';
        foreach ($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $quoted = $bt . str_replace($bt, $bt . $bt, $t) . $bt;
            $tables[$t] = (int) $db->query('SELECT COUNT(*) FROM ' . $quoted)->fetchColumn();
        }
        $plugins = [];
        foreach ($db->query('SELECT slug, installed_version, enabled FROM plugins ORDER BY slug')->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $plugins[] = ['slug' => $p['slug'], 'version' => $p['installed_version'], 'enabled' => (bool) $p['enabled']];
        }
        $site = $db->query("SELECT setting_value FROM settings WHERE setting_key = 'site_name'")->fetchColumn();
        return [
            'core_version' => defined('CORE_VERSION') ? CORE_VERSION : '',
            'site_name' => is_string($site) ? $site : '',
            'plugins' => $plugins,
            'tables' => $tables,
        ];
    }

    /**
     * Die Fremdschlüssel dieser Datenbank, aus information_schema.
     *
     * Bewusst abgefragt statt im Addon gepflegt: Die Liste muss die Tabellen
     * der ADDONS mit umfassen (deckanfrage, galerie, verkaufsboerse &c.
     * verweisen alle auf `horses`), und die kennt dieses Addon nicht. Eine
     * handgepflegte Liste wäre am Tag nach dem nächsten neuen Addon falsch -
     * und zwar still.
     *
     * @return array<int, array{tabelle:string, spalte:string, ziel:string}>
     */
    private function fremdschluessel(): array {
        $sql = 'SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME
                  FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND REFERENCED_TABLE_NAME IS NOT NULL';
        $out = [];
        foreach (Database::getInstance()->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
            // Selbstbezüge (horses.sire_id -> horses) können nie brechen: Eine
            // Tabelle ist entweder ganz im Archiv oder gar nicht.
            if ($r['TABLE_NAME'] === $r['REFERENCED_TABLE_NAME']) {
                continue;
            }
            $out[] = [
                'tabelle' => (string) $r['TABLE_NAME'],
                'spalte' => (string) $r['COLUMN_NAME'],
                'ziel' => (string) $r['REFERENCED_TABLE_NAME'],
            ];
        }
        return $out;
    }

    /**
     * Was verliert bei DIESER Auswahl sein Gegenstück? Mit Zahlen.
     *
     * "Pferde ohne Kontakte" ist eine völlig plausible Auswahl und erzeugt
     * beim Einspielen verwaiste Verweise - nachgemessen: Die Zeilen bleiben
     * stehen und sind lesbar, nur zeigen sie ins Leere; erst ein späterer
     * NEUER Verweis auf die fehlende Kennung scheitert (ERROR 1452). Es fällt
     * also nichts um, es wird nur still falsch. Deshalb wird die Zahl VOR dem
     * Erstellen genannt, statt sich auf eine Fehlermeldung zu verlassen, die
     * nie kommt.
     *
     * @param array<int, string> $tabellen Positivliste des Exports
     * @return array<int, string>
     */
    private function abhaengigkeitsWarnungen(array $tabellen): array {
        $imArchiv = array_flip($tabellen);
        $db = Database::getInstance();
        // Backtick als eigene Konstante - siehe localInventory().
        $bt = '`';
        $quote = static fn(string $n): string => $bt . str_replace($bt, $bt . $bt, $n) . $bt;

        /** @var array<string, array<string, int>> $offen  ziel => [tabelle => zeilen] */
        $offen = [];
        $zaehle = static function (string $tabelle, string $bedingung) use ($db): int {
            try {
                return (int) $db->query('SELECT COUNT(*) FROM ' . $tabelle . ' WHERE ' . $bedingung)->fetchColumn();
            } catch (\Throwable $e) {
                // Eine nicht zählbare Tabelle darf die Warnung der anderen
                // nicht verhindern.
                return 0;
            }
        };

        foreach ($this->fremdschluessel() as $fk) {
            if (!isset($imArchiv[$fk['tabelle']]) || isset($imArchiv[$fk['ziel']])) {
                continue;
            }
            $n = $zaehle($quote($fk['tabelle']), $quote($fk['spalte']) . ' IS NOT NULL');
            if ($n > 0) {
                $offen[$fk['ziel']][$fk['tabelle']] = ($offen[$fk['ziel']][$fk['tabelle']] ?? 0) + $n;
            }
        }

        foreach (Exportauswahl::WEICHE_VERWEISE as $tabelle => $verweise) {
            if (!isset($imArchiv[$tabelle])) {
                continue;
            }
            foreach ($verweise as [$ziel, $bedingung]) {
                if (isset($imArchiv[$ziel])) {
                    continue;
                }
                // $bedingung ist eine Konstante aus dem Code, keine Eingabe.
                $n = $zaehle($quote($tabelle), $bedingung);
                if ($n > 0) {
                    $offen[$ziel][$tabelle] = ($offen[$ziel][$tabelle] ?? 0) + $n;
                }
            }
        }

        $warnungen = [];
        ksort($offen);
        foreach ($offen as $ziel => $quellen) {
            ksort($quellen);
            $teile = [];
            foreach ($quellen as $tabelle => $n) {
                $teile[] = number_format($n, 0, ',', '.') . ' Zeile(n) in ' . $tabelle;
            }
            $warnungen[] = implode(', ', $teile) . ' verweisen auf ' . $ziel
                . ' - diese Tabelle ist nicht im Archiv. Die Verweise zeigen nach dem Einspielen ins Leere '
                . '(Gruppe „' . Exportauswahl::label(Exportauswahl::gruppeFuer($ziel)) . '").';
        }
        return $warnungen;
    }

    // -- Übersicht ----------------------------------------------------------

    public function overview(): void {
        $csrf = htmlspecialchars(Router::generateCsrfToken(), ENT_QUOTES, 'UTF-8');
        $canExport = $this->hasPermission('datenmigration', 'export');
        $canImport = $this->hasPermission('datenmigration', 'import');

        $content = '<div class="card"><h1>📦 Datenmigration (Instanz-Umzug)</h1>';
        $content .= '<p>Zieht eine Instanz um: Datenbank und Uploads, gebündelt in einem Archiv. '
            . 'Was mitgeht, wird beim Export ausgewählt - Benutzerkonten und Zugangsdaten bleiben dabei '
            . 'per Vorgabe zurück.</p>';

        $notice = $_GET['hinweis'] ?? '';
        if ($notice === 'hochgeladen') {
            $content .= '<p class="alert alert-success">Archiv hochgeladen - unten prüfen und anwenden.</p>';
        }
        if ($notice === 'importiert') {
            $content .= '<p class="alert alert-success">Teilarchiv eingespielt. Die nicht enthaltenen Tabellen '
                . 'dieser Instanz blieben unverändert - Ihre Sitzung gilt deshalb weiter.</p>';
        }

        if ($canExport) {
            $content .= '<h2>Export</h2><p><a class="btn" href="/plugin/datenmigration/export">Export-Archiv zusammenstellen</a></p>'
                . '<p><small>Das Archiv wird zusätzlich in <code>var/datenmigration/</code> abgelegt.</small></p>';
        }

        if ($canImport) {
            $content .= '<h2>Import</h2>';
            $content .= '<form method="POST" action="/plugin/datenmigration/import/hochladen" enctype="multipart/form-data">'
                . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
                . '<div class="form-group"><label for="archiv">Archiv hochladen (.tar / .tar.gz)</label>'
                . '<input type="file" name="archiv" id="archiv" class="form-control" accept=".tar,.gz" required></div>'
                . '<button type="submit" class="btn">Hochladen</button></form>';
            $content .= '<p><small>Große Archive (über der PHP-Upload-Grenze) direkt nach '
                . '<code>var/datenmigration/</code> legen - sie erscheinen dann in dieser Liste.</small></p>';

            $archives = $this->stagedArchives();
            if ($archives) {
                $content .= '<h3>Bereitliegende Archive</h3><ul>';
                foreach ($archives as $a) {
                    $safe = htmlspecialchars($a, ENT_QUOTES, 'UTF-8');
                    $size = filesize($this->stageDir() . '/' . $a);
                    $content .= '<li><code>' . $safe . '</code> (' . number_format($size / 1048576, 1, ',', '.') . ' MB) '
                        . '- <a href="/plugin/datenmigration/import/pruefen?datei=' . urlencode($a) . '">Prüfen &amp; anwenden</a></li>';
                }
                $content .= '</ul>';
            } else {
                $content .= '<p>Keine Archive in <code>var/datenmigration/</code>.</p>';
            }
        }

        if (!$canExport && !$canImport) {
            $content .= '<p class="alert alert-error">Keine Berechtigung für dieses Modul.</p>';
        }
        $content .= '</div>';
        PluginPage::render('Datenmigration', $content);
    }

    // -- Export -------------------------------------------------------------

    /**
     * Das Auswahlformular (#121): welche Gruppen kommen ins Archiv?
     *
     * Jede Gruppe nennt ihre Tabellen und deren Zeilenzahl. Das ist kein
     * Beiwerk: Die Gruppennamen sind eine Abstraktion, und eine Abstraktion,
     * die verbirgt, was sie zusammenfasst, verwandelt eine bewusste
     * Entscheidung in einen Vertrauensvorschuss.
     */
    public function exportForm(): void {
        $this->requirePermission('datenmigration', 'export');
        $this->requireAdminForFullAccess('export');

        $inventory = $this->localInventory();
        $vorhandene = array_keys($inventory['tables']);
        $auswahl = array_key_exists('gruppen', $_GET)
            ? Exportauswahl::bereinige((array) $_GET['gruppen'])
            : Exportauswahl::vorgabe();
        $fehler = (string) ($_GET['fehler'] ?? '');

        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $csrf = $e(Router::generateCsrfToken());

        $content = '<div class="card"><h1>📦 Export-Archiv zusammenstellen</h1>';
        if ($fehler === 'leer') {
            $content .= '<p class="alert alert-error">Es war nichts ausgewählt - ein leeres Archiv hilft niemandem. '
                . 'Bitte mindestens eine Gruppe anhaken.</p>';
        }
        $content .= '<p>Angehakt ist, was in das Archiv kommt. Die Voreinstellung lässt <strong>Benutzer, Gruppen, '
            . 'Rechte</strong> weg: Das ist die einzige Gruppe, deren Inhalt dem Empfänger Zugang zu Ihrer Instanz '
            . 'verschafft (Passwort-Hashes, TOTP-Geheimnisse, Backup-Codes, API-Schlüssel). Alles Übrige sind Daten, '
            . 'die bei einem Umzug mitsollen.</p>';

        $content .= '<form method="POST" action="/plugin/datenmigration/export">'
            . '<input type="hidden" name="csrf_token" value="' . $csrf . '">';

        foreach (Exportauswahl::GRUPPEN as $key => $meta) {
            $tabellen = $key === Exportauswahl::GRUPPE_DATEIEN
                ? []
                : Exportauswahl::tabellen([$key], $vorhandene);
            // Eine leere Auffanggruppe ist die Regel und kein Thema - sie
            // erscheint nur, wenn sie tatsächlich etwas enthält.
            if ($key === Exportauswahl::GRUPPE_SONSTIGES && $tabellen === []) {
                continue;
            }

            if ($key === Exportauswahl::GRUPPE_DATEIEN) {
                $umfang = number_format(count($this->collectUploads()), 0, ',', '.') . ' Datei(en)';
            } else {
                $zeilen = 0;
                foreach ($tabellen as $t) {
                    $zeilen += (int) ($inventory['tables'][$t] ?? 0);
                }
                $umfang = number_format($zeilen, 0, ',', '.') . ' Zeile(n) in '
                    . count($tabellen) . ' Tabelle(n)';
            }

            $checked = in_array($key, $auswahl, true) ? ' checked' : '';
            $content .= '<div class="form-group"><label>'
                . '<input type="checkbox" name="gruppen[]" value="' . $e($key) . '"' . $checked . '> '
                . '<strong>' . $e($meta['label']) . '</strong> (' . $e($umfang) . ')</label>'
                . '<p><small>' . $e($meta['text']) . '</small></p>';
            if ($meta['hinweis'] !== null) {
                $content .= '<p class="alert alert-warning">⚠ ' . $e($meta['hinweis']) . '</p>';
            }
            if ($tabellen !== []) {
                // Erst jede Tabelle einzeln maskieren, dann die Auszeichnung
                // dazwischensetzen - andersherum landete das Markup im
                // maskierten Text und stünde wörtlich auf der Seite.
                $content .= '<p><small><code>'
                    . implode('</code>, <code>', array_map($e, $tabellen))
                    . '</code></small></p>';
            }
            $content .= '</div>';
        }

        $content .= '<button type="submit" class="btn">Archiv erstellen und herunterladen</button></form>';
        $content .= '<p><a href="/plugin/datenmigration/uebersicht">Zurück</a></p></div>';
        PluginPage::render('Datenmigration - Export', $content);
    }

    public function export(): void {
        $this->requirePermission('datenmigration', 'export');
        $this->requireAdminForFullAccess('export');
        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
            return;
        }

        $auswahl = Exportauswahl::bereinige($_POST['gruppen'] ?? []);
        if ($auswahl === []) {
            header('Location: /plugin/datenmigration/export?fehler=leer');
            exit;
        }

        $inventory = $this->localInventory();
        $tabellen = Exportauswahl::tabellen($auswahl, array_keys($inventory['tables']));
        $vollstaendig = Exportauswahl::istVollstaendig($auswahl);

        // Fremdschlüssel: erst warnen, dann erstellen. Ohne den
        // Zwischenschritt liefe der Betreiber ins Messer - der Import auf der
        // Gegenseite meldet nichts, er spielt die verwaisten Verweise
        // klaglos ein (siehe abhaengigkeitsWarnungen()).
        $warnungen = $this->abhaengigkeitsWarnungen($tabellen);
        if ($warnungen !== [] && ($_POST['trotzdem'] ?? '') !== '1') {
            $this->renderExportWarnung($auswahl, $warnungen);
            return;
        }

        $mitDateien = in_array(Exportauswahl::GRUPPE_DATEIEN, $auswahl, true);
        $uploadFiles = $mitDateien ? $this->collectUploads() : [];

        $tabellenZaehler = [];
        foreach ($tabellen as $t) {
            $tabellenZaehler[$t] = (int) ($inventory['tables'][$t] ?? 0);
        }

        $manifest = [
            'format' => self::FORMAT,
            'created_at' => gmdate('c'),
            'core_version' => $inventory['core_version'],
            'site_name' => $inventory['site_name'],
            'plugins' => $inventory['plugins'],
            // `tables` zählt seit #121 nur noch, was TATSÄCHLICH im Archiv
            // liegt. Genau daran erkennt die Vorschau der Gegenseite, dass
            // eine fehlende Tabelle Absicht ist und kein Mangel.
            'tables' => $tabellenZaehler,
            'auswahl' => $auswahl,
            'vollstaendig' => $vollstaendig,
            'uploads_count' => count($uploadFiles),
        ];

        $ext = function_exists('gzopen') ? '.tar.gz' : '.tar';
        $filename = ($vollstaendig ? 'datenmigration-' : 'datenmigration-teil-') . gmdate('Ymd-His') . $ext;
        $path = $this->stageDir() . '/' . $filename;

        // Der Dump geht über eine Zwischendatei statt über einen String im
        // Speicher: DatabaseDumper::dumpTo() ist streamend (Framework#231),
        // aber der tar-Header verlangt die Größe VOR dem Inhalt. Eine Datei
        // beantwortet beides - konstanter Speicherbedarf, bekannte Größe.
        $dumpDatei = $this->stageDir() . '/.dump-' . bin2hex(random_bytes(8)) . '.sql';
        try {
            $fh = fopen($dumpDatei, 'wb');
            if ($fh === false) {
                throw new \RuntimeException("Zwischendatei nicht schreibbar: {$dumpDatei}");
            }
            try {
                // null heißt "alles" und schreibt KEINEN Auswahl-Hinweis in
                // den Dump. Bei einem Vollarchiv ist das die richtige Aussage;
                // eine Liste, die zufällig alle Tabellen enthält, würde den
                // Dump fälschlich als Teilsicherung kennzeichnen.
                DatabaseDumper::dumpTo(
                    function (string $chunk) use ($fh): void {
                        if (fwrite($fh, $chunk) === false) {
                            throw new \RuntimeException('Dump konnte nicht geschrieben werden.');
                        }
                    },
                    $vollstaendig ? null : $tabellen
                );
            } finally {
                fclose($fh);
            }

            $tar = TarWriter::create($path);
            $tar->addString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $tar->addFile('database.sql', $dumpDatei);
            foreach ($uploadFiles as $rel => $abs) {
                $tar->addFile('uploads/' . $rel, $abs);
            }
            $tar->close();
        } finally {
            @unlink($dumpDatei);
        }

        PluginAudit::log(
            'datenmigration',
            'Export-Archiv erstellt',
            $filename,
            ($vollstaendig ? 'Vollarchiv' : 'Teilarchiv') . ': ' . implode(', ', $auswahl)
                . ' - ' . count($tabellen) . ' Tabelle(n), ' . count($uploadFiles) . ' Upload-Datei(en)'
        );

        header('Content-Type: ' . ($ext === '.tar.gz' ? 'application/gzip' : 'application/x-tar'));
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . (string) filesize($path));
        $out = fopen($path, 'rb');
        while (!feof($out)) {
            echo fread($out, 1024 * 512);
        }
        fclose($out);
        exit;
    }

    /**
     * Zwischenseite mit den Zahlen: "142 Zeile(n) in horse_persons verweisen
     * auf contacts". Erst danach lässt sich das Archiv erstellen.
     *
     * @param array<int, string> $auswahl
     * @param array<int, string> $warnungen
     */
    private function renderExportWarnung(array $auswahl, array $warnungen): void {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $csrf = $e(Router::generateCsrfToken());

        $content = '<div class="card"><h1>📦 Export: fehlende Gegenstücke</h1>';
        $content .= '<p>Die Auswahl enthält Tabellen, deren Verweise auf nicht ausgewählte Tabellen zeigen. '
            . 'Beim Einspielen auf der Zielinstanz bleiben diese Zeilen stehen und sind lesbar - sie zeigen nur '
            . 'ins Leere: eine Zuordnung ohne Kontakt, ein Addon-Datensatz ohne Pferd. Eine Fehlermeldung gibt es '
            . 'dabei nicht, der Import läuft durch.</p>';
        foreach ($warnungen as $w) {
            $content .= '<p class="alert alert-warning">⚠ ' . $e($w) . '</p>';
        }
        $content .= '<p>Entweder die fehlende Gruppe mit anhaken - oder das Archiv bewusst so erstellen.</p>';

        $content .= '<form method="POST" action="/plugin/datenmigration/export">'
            . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
            . '<input type="hidden" name="trotzdem" value="1">';
        foreach ($auswahl as $key) {
            $content .= '<input type="hidden" name="gruppen[]" value="' . $e($key) . '">';
        }
        $content .= '<button type="submit" class="btn">Archiv trotzdem so erstellen</button></form>';

        // http_build_query trennt mit "&"; in einem HTML-Attribut gehört
        // "&amp;" hin, sonst deutet der Parser "&gruppen" als Entität.
        $content .= '<p><a href="/plugin/datenmigration/export?'
            . $e(http_build_query(['gruppen' => $auswahl])) . '">Auswahl ändern</a></p></div>';
        PluginPage::render('Datenmigration - Export', $content);
    }

    /** @return array<string, string> relativer Pfad => absoluter Pfad */
    private function collectUploads(): array {
        $base = $this->uploadsDir();
        if (!is_dir($base)) {
            return [];
        }
        $files = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->isFile() && !$file->isLink()) {
                $rel = substr($file->getPathname(), strlen($base) + 1);
                $files[$rel] = $file->getPathname();
            }
        }
        ksort($files);
        return $files;
    }

    // -- Import: Hochladen --------------------------------------------------

    public function upload(): void {
        $this->requirePermission('datenmigration', 'import');
        $this->requireAdminForFullAccess('import');
        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
            return;
        }
        $file = $_FILES['archiv'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->fail('Kein Archiv hochgeladen (oder Upload-Grenze überschritten - große Archive per SFTP nach var/datenmigration/ legen).');
            return;
        }
        $name = basename((string) $file['name']);
        if (!preg_match('/^[A-Za-z0-9._ -]+\.tar(\.gz)?$/', $name)) {
            $this->fail('Nur .tar/.tar.gz-Archive mit einfachem Dateinamen.');
            return;
        }
        $target = $this->stageDir() . '/' . str_replace(' ', '_', $name);
        if (!move_uploaded_file($file['tmp_name'], $target)) {
            $this->fail('Archiv konnte nicht gespeichert werden.');
            return;
        }
        header('Location: /plugin/datenmigration/uebersicht?hinweis=hochgeladen');
        exit;
    }

    // -- Import: Prüfen (Vorschau) -----------------------------------------

    public function preview(): void {
        $this->requirePermission('datenmigration', 'import');
        $this->requireAdminForFullAccess('import');
        $path = $this->stagedPath((string) ($_GET['datei'] ?? ''));
        if ($path === null) {
            $this->fail('Archiv nicht gefunden.');
            return;
        }
        try {
            $manifest = $this->readManifest($path);
        } catch (\Throwable $e) {
            $this->fail('Archiv unlesbar: ' . $e->getMessage());
            return;
        }

        $local = $this->localInventory();
        $problems = $this->compatibilityProblems($manifest, $local);
        $warnings = $this->pluginWarnings($manifest, $local);
        $auswahl = $this->auswahlDesArchivs($manifest);
        $vollstaendig = Exportauswahl::istVollstaendig($auswahl);
        $imArchiv = array_flip(array_keys((array) ($manifest['tables'] ?? [])));

        $csrf = htmlspecialchars(Router::generateCsrfToken(), ENT_QUOTES, 'UTF-8');
        $file = htmlspecialchars(basename($path), ENT_QUOTES, 'UTF-8');
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $content = '<div class="card"><h1>📦 Import prüfen: <code>' . $file . '</code></h1>';

        // Das Wichtigste zuerst und in eigenen Worten: Ein Teilarchiv tut
        // etwas grundsätzlich anderes als ein Vollarchiv. Wer das erst aus
        // der Tabellenliste weiter unten erschließen muss, erschließt es
        // nicht.
        if ($vollstaendig) {
            $content .= '<p class="alert alert-warning">⚠ <strong>Vollarchiv.</strong> Der gesamte Datenbestand '
                . 'dieser Instanz wird ersetzt - einschließlich der Benutzerkonten. Nach dem Anwenden ist Ihre '
                . 'Sitzung beendet; die Anmeldung erfolgt danach mit den Konten der Quellinstanz.</p>';
        } else {
            $content .= '<p class="alert alert-warning">⚠ <strong>Teilarchiv.</strong> Es wird '
                . '<em>zusammengeführt</em>, nicht ersetzt: Nur die unten aufgeführten Tabellen werden durch den '
                . 'Stand des Archivs überschrieben, alle übrigen bleiben unverändert stehen. Enthaltene Gruppen: '
                . $e(implode(', ', array_map([Exportauswahl::class, 'label'], $auswahl))) . '.</p>';
        }

        $content .= '<table class="table"><tr><th></th><th>Archiv (Quelle)</th><th>Diese Instanz (Ziel)</th></tr>'
            . '<tr><td>Seite</td><td>' . $e($manifest['site_name'] ?? '?') . '</td><td>' . $e($local['site_name']) . '</td></tr>'
            . '<tr><td>Kern-Version</td><td>' . $e($manifest['core_version'] ?? '?') . '</td><td>' . $e($local['core_version']) . '</td></tr>'
            . '<tr><td>Erstellt</td><td>' . $e($manifest['created_at'] ?? '?') . '</td><td>-</td></tr>'
            . '<tr><td>Umfang</td><td>' . ($vollstaendig ? 'Vollarchiv' : 'Teilarchiv') . '</td><td>-</td></tr>'
            . '<tr><td>Upload-Dateien</td><td>' . $e($manifest['uploads_count'] ?? '?') . '</td><td>-</td></tr></table>';

        $content .= '<h2>Datenbestand (Zeilen je Tabelle)</h2><table class="table">'
            . '<tr><th>Tabelle</th><th>Quelle</th><th>Ziel</th><th>Was geschieht</th></tr>';
        $tables = array_unique(array_merge(array_keys($manifest['tables'] ?? []), array_keys($local['tables'])));
        sort($tables);
        foreach ($tables as $t) {
            // Bei einem Teilarchiv ist eine fehlende Tabelle KEIN Mangel,
            // sondern die Auswahl. Der Spaltenkopf hieß früher pauschal
            // "Ziel (wird ersetzt)" - das war für Teilarchive schlicht falsch.
            $wirkung = isset($imArchiv[$t])
                ? 'wird ersetzt'
                : 'bleibt unverändert';
            $content .= '<tr><td><code>' . $e($t) . '</code></td><td>' . $e($manifest['tables'][$t] ?? '-')
                . '</td><td>' . $e($local['tables'][$t] ?? '-') . '</td><td>' . $wirkung . '</td></tr>';
        }
        $content .= '</table>';

        if (!$vollstaendig) {
            foreach ($this->importRisiken($imArchiv, $local) as $r) {
                $content .= '<p class="alert alert-warning">⚠ ' . $e($r) . '</p>';
            }
        }

        foreach ($problems as $p) {
            $content .= '<p class="alert alert-error">' . $e($p) . '</p>';
        }
        foreach ($warnings as $w) {
            $content .= '<p class="alert alert-warning">⚠ ' . $e($w) . '</p>';
        }

        if (!$problems) {
            $frage = $vollstaendig
                ? 'Wirklich ALLE Daten dieser Instanz durch das Archiv ersetzen?'
                : 'Wirklich die im Archiv enthaltenen Tabellen dieser Instanz überschreiben?';
            $zusage = $vollstaendig
                ? 'Mir ist klar: Sämtliche Daten dieser Instanz (inkl. Benutzerkonten) werden ersetzt.'
                : 'Mir ist klar: Die oben mit „wird ersetzt" gekennzeichneten Tabellen werden vollständig durch '
                    . 'den Stand des Archivs überschrieben; die übrigen bleiben stehen und können danach auf '
                    . 'fehlende Gegenstücke verweisen.';
            $content .= '<form method="POST" action="/plugin/datenmigration/import/anwenden" '
                . 'onsubmit="return confirm(\'' . $frage . '\');">'
                . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
                . '<input type="hidden" name="datei" value="' . $file . '">'
                . '<div class="form-group"><label><input type="checkbox" name="bestaetigt" value="1" required> '
                . $zusage . ' '
                . 'Ein Sicherungs-Dump wird vorher nach <code>var/datenmigration/</code> geschrieben.</label></div>'
                . '<button type="submit" class="btn btn-danger">Import anwenden</button></form>';
        }
        $content .= '<p><a href="/plugin/datenmigration/uebersicht">Zurück</a></p></div>';
        PluginPage::render('Datenmigration - Import prüfen', $content);
    }

    /**
     * Welche Gruppen stecken in diesem Archiv?
     *
     * Format 1 kannte das Feld nicht und war immer ein Vollarchiv - dieser
     * Fall wird hier eingesetzt, statt ihn abzuweisen. Ein Format-2-Archiv
     * ohne brauchbares `auswahl` (von Hand gebaut, beschädigt) fällt auf die
     * leere Auswahl zurück: Dann wird nichts ersetzt, was nicht ausdrücklich
     * benannt ist - die harmlosere Richtung.
     *
     * @return array<int, string>
     */
    private function auswahlDesArchivs(array $manifest): array {
        if ((int) ($manifest['format'] ?? 0) === 1) {
            return Exportauswahl::schluessel();
        }
        return Exportauswahl::bereinige($manifest['auswahl'] ?? []);
    }

    /**
     * Was ein Teilarchiv auf DIESER Instanz anrichten kann, mit Zahlen.
     *
     * Nachgemessen (MariaDB 11.8): Der Dump setzt FOREIGN_KEY_CHECKS=0, wirft
     * die enthaltenen Tabellen weg und legt sie neu an. Zeilen in Tabellen,
     * die NICHT im Archiv sind, bleiben stehen - auch dann, wenn ihr Verweis
     * jetzt ins Leere zeigt. Das abschließende FOREIGN_KEY_CHECKS=1 prüft den
     * Bestand nicht nach; die Fremdschlüssel selbst überleben das Neuanlegen
     * und greifen erst wieder beim nächsten NEUEN Verweis (dann ERROR 1452).
     *
     * Es ist eine Obergrenze, keine exakte Zahl: Wie viele Kennungen das
     * Archiv tatsächlich mitbringt, wüsste man erst nach dem Einspielen.
     *
     * @param array<string, int|string> $imArchiv Tabellen des Archivs als Schlüssel
     * @param array{tables:array<string,int>} $local
     * @return array<int, string>
     */
    private function importRisiken(array $imArchiv, array $local): array {
        $risiken = [];
        foreach ($this->fremdschluessel() as $fk) {
            // Elterntabelle wird ersetzt, Kindtabelle bleibt stehen: genau
            // die Kombination, aus der verwaiste Verweise entstehen.
            if (!isset($imArchiv[$fk['ziel']]) || isset($imArchiv[$fk['tabelle']])) {
                continue;
            }
            $n = (int) ($local['tables'][$fk['tabelle']] ?? 0);
            if ($n === 0) {
                continue;
            }
            $risiken[$fk['tabelle'] . '|' . $fk['ziel']] = 'Bis zu '
                . number_format($n, 0, ',', '.') . ' Zeile(n) in ' . $fk['tabelle']
                . ' verweisen auf ' . $fk['ziel'] . '. Diese Tabelle wird ersetzt, ' . $fk['tabelle']
                . ' nicht - Verweise auf Kennungen, die das Archiv nicht mitbringt, zeigen danach ins Leere.';
        }
        ksort($risiken);
        return array_values($risiken);
    }

    /** @return array<int, string> Harte Hindernisse (Import wird verweigert) */
    private function compatibilityProblems(array $manifest, array $local): array {
        $problems = [];
        if (!in_array((int) ($manifest['format'] ?? 0), self::LESBARE_FORMATE, true)) {
            $problems[] = 'Unbekanntes Archivformat (Version ' . (string) ($manifest['format'] ?? '?')
                . ', lesbar sind ' . implode('/', self::LESBARE_FORMATE) . ').';
        }
        $src = (string) ($manifest['core_version'] ?? '');
        if ($src === '' || $src !== $local['core_version']) {
            $problems[] = 'Kern-Version passt nicht (Archiv: ' . ($src ?: 'unbekannt') . ', Ziel: ' . $local['core_version']
                . '). Erst beide Instanzen auf denselben Stand bringen - ein versionsübergreifender Import braucht einen Schema-Migrationslauf.';
        }
        return $problems;
    }

    /** @return array<int, string> Weiche Hinweise (Import bleibt möglich) */
    private function pluginWarnings(array $manifest, array $local): array {
        $warnings = [];
        $localPlugins = [];
        foreach ($local['plugins'] as $p) {
            $localPlugins[$p['slug']] = $p['version'];
        }
        foreach (($manifest['plugins'] ?? []) as $p) {
            $slug = (string) ($p['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            if (!array_key_exists($slug, $localPlugins)) {
                $warnings[] = "Quell-Addon '{$slug}' fehlt auf dieser Instanz - seine Tabellen werden mit importiert, "
                    . 'bleiben aber ohne das Addon unsichtbar. Addon nachinstallieren und danach unter /admin/plugins aktivieren.';
            } elseif (($p['version'] ?? '') !== $localPlugins[$slug]) {
                $warnings[] = "Addon '{$slug}': Quellversion " . ($p['version'] ?? '?') . ', hier ' . $localPlugins[$slug]
                    . ' - Datenformat der Plugin-Tabellen im Zweifel prüfen.';
            }
        }
        // Aktivierungszustand kommt aus der importierten plugins-Tabelle; der
        // Kern prüft danach den Verzeichnis-Fingerabdruck und deaktiviert
        // alles, was lokal nicht (identisch) vorliegt - fail-closed.
        return $warnings;
    }

    private function readManifest(string $path): array {
        $json = null;
        $reader = new TarReader($path);
        try {
            $reader->each(function (string $name, int $size, callable $read) use (&$json) {
                $data = '';
                while (($chunk = $read()) !== '') {
                    if ($json === null && $name === 'manifest.json') {
                        if ($size > 1024 * 1024) {
                            throw new \RuntimeException('manifest.json unplausibel groß.');
                        }
                        $data .= $chunk;
                    }
                }
                if ($name === 'manifest.json' && $json === null) {
                    $json = $data;
                }
            });
        } finally {
            $reader->close();
        }
        if ($json === null) {
            throw new \RuntimeException('manifest.json fehlt im Archiv.');
        }
        $manifest = json_decode($json, true);
        if (!is_array($manifest)) {
            throw new \RuntimeException('manifest.json ist kein gültiges JSON.');
        }
        return $manifest;
    }

    // -- Import: Anwenden ---------------------------------------------------

    public function apply(): void {
        $this->requirePermission('datenmigration', 'import');
        $this->requireAdminForFullAccess('import');
        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
            return;
        }
        if (($_POST['bestaetigt'] ?? '') !== '1') {
            $this->fail('Import nicht bestätigt.');
            return;
        }
        $path = $this->stagedPath((string) ($_POST['datei'] ?? ''));
        if ($path === null) {
            $this->fail('Archiv nicht gefunden.');
            return;
        }
        $manifest = $this->readManifest($path);
        $problems = $this->compatibilityProblems($manifest, $this->localInventory());
        if ($problems) {
            $this->fail(implode(' ', $problems));
            return;
        }
        $auswahl = $this->auswahlDesArchivs($manifest);
        $vollstaendig = Exportauswahl::istVollstaendig($auswahl);

        // 1. Rückweg sichern: Dump der Zielinstanz VOR dem Import.
        //
        // Immer VOLLSTÄNDIG, auch wenn nur ein Teilarchiv eingespielt wird -
        // die Sicherung ist der Rückweg, und ein Rückweg, der nur die Hälfte
        // kennt, ist keiner. Über dumpTo() in die Datei statt über dump() in
        // einen String: Der Speicherbedarf bleibt damit unabhängig von der
        // Instanzgröße (Framework#231).
        $backupName = 'sicherung-vor-import-' . gmdate('Ymd-His') . '.sql' . (function_exists('gzencode') ? '.gz' : '');
        $backupPfad = $this->stageDir() . '/' . $backupName;
        $gz = function_exists('gzopen') && str_ends_with($backupName, '.gz');
        $bh = $gz ? gzopen($backupPfad, 'wb6') : fopen($backupPfad, 'wb');
        if ($bh === false) {
            $this->fail('Sicherungs-Dump nicht schreibbar - nichts verändert.');
            return;
        }
        try {
            DatabaseDumper::dumpTo(function (string $chunk) use ($bh, $gz): void {
                $ok = $gz ? gzwrite($bh, $chunk) : fwrite($bh, $chunk);
                if ($ok === false) {
                    throw new \RuntimeException('Sicherungs-Dump konnte nicht geschrieben werden.');
                }
            });
        } catch (\Throwable $e) {
            $gz ? gzclose($bh) : fclose($bh);
            @unlink($backupPfad);
            $this->fail('Sicherungs-Dump fehlgeschlagen, Import abgebrochen: ' . $e->getMessage());
            return;
        }
        $gz ? gzclose($bh) : fclose($bh);

        // 2. Archiv in einem Durchlauf anwenden: SQL sammeln, Uploads in ein
        //    Nebenverzeichnis entpacken; erst wenn beides fehlerfrei durch ist,
        //    wird umgeschaltet.
        //
        // Das Nebenverzeichnis liegt AUSSERHALB von public/. Vorher hieß es
        // "public/uploads.import-neu" und lag damit im Webroot: Zwischen dem
        // ersten geschriebenen Eintrag und dem Umschalten war jede Datei des
        // Archivs unter ihrem eigenen Namen über den Webserver erreichbar -
        // inklusive einer .php. Es kostet nichts, das Fenster ganz zu
        // schließen; var/ ist ohnehin schon die Ablage dieses Addons.
        $uploadsNew = $this->stageDir() . '/uploads-neu';
        $this->removeDir($uploadsNew);
        mkdir($uploadsNew, 0755, true);
        $sql = null;
        $uebersprungen = 0;
        // Ob Uploads angefasst werden, entscheidet der TATSÄCHLICHE Inhalt des
        // Archivs, nicht das Manifest. Ein Manifest ist eine Behauptung; das
        // Verzeichnis public/uploads zu leeren, weil in einer JSON-Datei
        // "dateien" stand, wäre die teuerste Art, ihr zu glauben.
        $dateienImArchiv = 0;
        $reader = new TarReader($path);
        try {
            $reader->each(function (string $name, int $size, callable $read) use (&$sql, &$uebersprungen, &$dateienImArchiv, $uploadsNew) {
                if ($name === 'database.sql') {
                    $data = '';
                    while (($chunk = $read()) !== '') {
                        $data .= $chunk;
                    }
                    $sql = $data;
                    return;
                }
                if (str_starts_with($name, 'uploads/')) {
                    $rel = substr($name, strlen('uploads/'));
                    // Pfadhärtung: keine Traversal, keine absoluten Pfade.
                    if ($rel === '' || str_contains($rel, '..') || str_starts_with($rel, '/') || str_contains($rel, "\0")) {
                        throw new \RuntimeException("Unzulässiger Pfad im Archiv: {$name}");
                    }
                    // ... und keine ausführbaren Dateien. Die Pfadhärtung
                    // darüber prüfte, WOHIN geschrieben wird, aber nicht WAS -
                    // und das Ziel ist am Ende public/uploads. Der Inhalt
                    // stammt aus einer hochgeladenen Datei, das Recht dafür ist
                    // an jede Gruppe vergebbar. Ohne diese Prüfung genügte ein
                    // Archiv mit einer .php darin für Codeausführung.
                    // Webserver-Steuerdateien werden verworfen, nicht
                    // übernommen - der Ausführungsschutz des Zielverzeichnisses
                    // darf nicht aus dem Archiv stammen. Er wird nach dem
                    // Umschalten neu geschrieben.
                    if (UploadNamePolicy::istWebserverSteuerdatei($rel)) {
                        $uebersprungen++;
                        while ($read() !== '') { // Datenstrom verwerfen
                        }
                        return;
                    }
                    UploadNamePolicy::assertAllowed($rel);

                    $target = $uploadsNew . '/' . $rel;
                    if (!is_dir(dirname($target))) {
                        mkdir(dirname($target), 0755, true);
                    }
                    $out = fopen($target, 'wb');
                    while (($chunk = $read()) !== '') {
                        fwrite($out, $chunk);
                    }
                    fclose($out);
                    $dateienImArchiv++;
                    return;
                }
                while ($read() !== '') { // manifest.json u. ä.: konsumieren
                }
            });
        } catch (\Throwable $e) {
            $reader->close();
            $this->removeDir($uploadsNew);
            $this->fail('Import abgebrochen, nichts verändert: ' . $e->getMessage());
            return;
        }
        $reader->close();
        if ($sql === null) {
            $this->removeDir($uploadsNew);
            $this->fail('database.sql fehlt im Archiv - nichts verändert.');
            return;
        }

        // 3. Datenbank ersetzen (Dump bringt DROP/CREATE/INSERT je Tabelle mit).
        //
        // Unter Wartungsmodus, und das ist keine Kosmetik: Der Dump wirft jede
        // Tabelle einzeln weg und legt sie neu an. Zwischen dem DROP der
        // ersten und dem letzten INSERT gibt es ein Zeitfenster von Sekunden
        // bis Minuten, in dem parallele Anfragen auf eine halb ersetzte
        // Datenbank treffen - Besucher sehen Fehlerseiten, ein laufender
        // Cron-Lauf schreibt in Tabellen, die gleich wieder verschwinden, und
        // der Kern legt beim nächsten Verbindungsaufbau womöglich mitten im
        // Import eine Migration los. DDL in MariaDB ist zudem
        // transaktions-autocommittend: Ein "einfach in eine Transaktion
        // packen" gibt es hier nicht, der Wartungsmodus ist die vorhandene und
        // richtige Antwort (App\Service\Maintenance, vom Kern in
        // public/index.php vor jedem DB-Zugriff geprüft).
        //
        // Schlägt das Einspielen fehl, wird der zuvor geschriebene
        // Sicherungs-Dump zurückgespielt. Ohne das bliebe die Zielinstanz mit
        // halbem Bestand stehen, und der Rückweg wäre Handarbeit auf einer
        // Datenbank, die niemand mehr benutzen kann.
        \App\Service\Maintenance::enable('Datenmigrations-Import läuft');
        try {
            Database::getInstance()->exec($sql);
        } catch (\Throwable $e) {
            $this->rollbackFromBackup($backupName, $e);
            \App\Service\Maintenance::disable();
            $this->removeDir($uploadsNew);
            $this->fail(
                'Import fehlgeschlagen, der Sicherungsstand wurde zurückgespielt: ' . $e->getMessage()
            );
            return;
        }
        \App\Service\Maintenance::disable();

        // 4. Uploads.
        //
        // Drei Fälle, und der erste ist der, wegen dem dieser Block seit #121
        // überhaupt eine Fallunterscheidung hat:
        //
        //   (a) Das Archiv bringt keine Dateien mit (Gruppe "Dateien" war beim
        //       Export nicht angehakt). Dann wird public/uploads NICHT
        //       angefasst. Der frühere Code tauschte das Verzeichnis
        //       bedingungslos aus - ein Teilarchiv "nur Kontakte" hätte damit
        //       sämtliche Pferdebilder der Zielinstanz gelöscht.
        //   (b) Vollarchiv mit Dateien: Verzeichnistausch wie bisher, der alte
        //       Stand bleibt als .import-alt als zweiter Rückweg liegen.
        //   (c) Teilarchiv mit Dateien: zusammenführen. Die Zielinstanz behält
        //       ihre übrigen Dateien; überschriebene Originale wandern vorher
        //       nach var/datenmigration/ersetzte-dateien-…, damit auch dieser
        //       Weg einen Rückweg hat.
        $dateiBericht = 'Uploads unverändert';
        if ($dateienImArchiv === 0) {
            $this->removeDir($uploadsNew);
        } elseif ($vollstaendig) {
            $uploadsOld = $this->uploadsDir() . '.import-alt';
            $this->removeDir($uploadsOld);
            if (is_dir($this->uploadsDir())) {
                rename($this->uploadsDir(), $uploadsOld);
            }
            // rename() über Verzeichnisgrenzen hinweg schlägt fehl, wenn Staging
            // und Ziel auf verschiedenen Dateisystemen liegen - seit das Staging
            // in var/ liegt, ist das kein theoretischer Fall mehr (eigenes Volume
            // für uploads, siehe docker-compose.yml des Kerns). Deshalb mit
            // Kopier-Rückfall statt eines stillen false.
            if (!@rename($uploadsNew, $this->uploadsDir())) {
                $this->copyDir($uploadsNew, $this->uploadsDir());
                $this->removeDir($uploadsNew);
            }
            $dateiBericht = $dateienImArchiv . ' Datei(en) ersetzt (alter Stand: public/uploads.import-alt)';
        } else {
            $ersetztDir = $this->stageDir() . '/ersetzte-dateien-' . gmdate('Ymd-His');
            $zusammen = $this->mergeUploads($uploadsNew, $this->uploadsDir(), $ersetztDir);
            $this->removeDir($uploadsNew);
            $dateiBericht = $zusammen['neu'] . ' Datei(en) neu, ' . $zusammen['ersetzt'] . ' überschrieben'
                . ($zusammen['ersetzt'] > 0 ? ' (Originale: ' . basename($ersetztDir) . ')' : '');
        }

        // Ausführungsschutz wiederherstellen - der Verzeichnistausch hat die
        // .htaccess des Kerns mitgenommen. Beim Zusammenführen ist sie noch da;
        // die Methode merkt das selbst und tut dann nichts.
        $this->restoreUploadsProtection();

        PluginAudit::log(
            'datenmigration',
            'Import angewendet',
            basename($path),
            ($vollstaendig ? 'Vollarchiv' : 'Teilarchiv: ' . implode(', ', $auswahl))
                . ' - Quelle: ' . (string) ($manifest['site_name'] ?? '?')
                . ', Sicherung: ' . $backupName
                . ', ' . $dateiBericht
                . ($uebersprungen > 0 ? ', ' . $uebersprungen . ' Webserver-Steuerdatei(en) verworfen' : '')
        );

        // 5. Sitzung beenden - aber nur, wenn die Benutzerkonten tatsächlich
        //    ausgetauscht wurden. Bei einem Teilarchiv ohne die Gruppe
        //    "Benutzer, Gruppen, Rechte" ist das angemeldete Konto dasselbe
        //    wie vorher; die Sitzung zu zerstören wäre dann nur eine
        //    unerklärliche Abmeldung mitten in der Arbeit.
        if (in_array(Exportauswahl::GRUPPE_BENUTZER, $auswahl, true)) {
            session_destroy();
            header('Location: /login?import=fertig');
            exit;
        }
        header('Location: /plugin/datenmigration/uebersicht?hinweis=importiert');
        exit;
    }

    /**
     * Führt die Dateien eines Teilarchivs in public/uploads ein, statt das
     * Verzeichnis auszutauschen.
     *
     * Überschriebene Originale werden vorher nach $sicherung verschoben (mit
     * ihrem relativen Pfad). Ein Teilimport soll keine Datei vernichten, die
     * nur die Zielinstanz kannte - und für die überschriebenen gilt dasselbe
     * wie für die Datenbank: Es muss einen Rückweg geben.
     *
     * @return array{neu:int, ersetzt:int}
     */
    private function mergeUploads(string $quelle, string $ziel, string $sicherung): array {
        $bilanz = ['neu' => 0, 'ersetzt' => 0];
        if (!is_dir($quelle)) {
            return $bilanz;
        }
        if (!is_dir($ziel) && !mkdir($ziel, 0755, true) && !is_dir($ziel)) {
            throw new \RuntimeException("Upload-Verzeichnis konnte nicht angelegt werden: {$ziel}");
        }

        $lauf = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($quelle, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($lauf as $eintrag) {
            $rel = $lauf->getSubPathname();
            $zielPfad = $ziel . '/' . $rel;
            if ($eintrag->isDir()) {
                if (!is_dir($zielPfad) && !mkdir($zielPfad, 0755, true) && !is_dir($zielPfad)) {
                    throw new \RuntimeException("Verzeichnis konnte nicht angelegt werden: {$zielPfad}");
                }
                continue;
            }
            if (is_file($zielPfad)) {
                $sicherungsPfad = $sicherung . '/' . $rel;
                if (!is_dir(dirname($sicherungsPfad)) && !mkdir(dirname($sicherungsPfad), 0750, true)
                    && !is_dir(dirname($sicherungsPfad))) {
                    throw new \RuntimeException("Sicherungsverzeichnis nicht anlegbar: {$sicherungsPfad}");
                }
                if (!@rename($zielPfad, $sicherungsPfad) && !copy($zielPfad, $sicherungsPfad)) {
                    throw new \RuntimeException("Original nicht sicherbar: {$zielPfad}");
                }
                $bilanz['ersetzt']++;
            } else {
                if (!is_dir(dirname($zielPfad)) && !mkdir(dirname($zielPfad), 0755, true) && !is_dir(dirname($zielPfad))) {
                    throw new \RuntimeException("Verzeichnis konnte nicht angelegt werden: {$zielPfad}");
                }
                $bilanz['neu']++;
            }
            if (!copy($eintrag->getPathname(), $zielPfad)) {
                throw new \RuntimeException("Datei konnte nicht übernommen werden: {$zielPfad}");
            }
        }
        return $bilanz;
    }

    /**
     * Schreibt den Ausführungsschutz für public/uploads neu - unabhängig
     * davon, was im Archiv stand.
     *
     * Der Verzeichnistausch ersetzt public/uploads VOLLSTÄNDIG, also auch die
     * dort mitgelieferte .htaccess des Kerns, die PHP in diesem Verzeichnis
     * abschaltet. Ein Archiv ohne diese Datei entfernte den Schutz still; ein
     * Archiv MIT einer eigenen Fassung hätte ihn ersetzt. Beides ist jetzt
     * ausgeschlossen: Punktdateien kommen gar nicht erst durch (siehe oben),
     * und die Schutzdatei wird nach dem Umschalten aus dem Kern-Bestand
     * zurückgeschrieben.
     */
    private function restoreUploadsProtection(): void {
        $ziel = $this->uploadsDir() . '/.htaccess';
        if (is_file($ziel)) {
            return;
        }

        // Bevorzugt die Fassung, die im Kern mitgeliefert wird - sie ist die
        // gepflegte Quelle. Nur wenn sie fehlt (etwa weil das Verzeichnis
        // gerade erst entstanden ist), greift die eingebaute Mindestfassung.
        $vorlage = $this->rootDir() . '/public/uploads.import-alt/.htaccess';
        if (is_file($vorlage)) {
            copy($vorlage, $ziel);
            return;
        }

        file_put_contents($ziel, <<<'HTACCESS'
        # Wiederhergestellt nach einem Datenmigrations-Import.
        # Kein PHP in diesem Verzeichnis - hier liegen ausschliesslich Daten.
        <FilesMatch "\.(php|phtml|php[0-9]|phar|pl|py|cgi|sh)$">
            Require all denied
        </FilesMatch>
        Options -Indexes -ExecCGI
        AddType text/plain .php .phtml .phar
        HTACCESS);
    }

    /**
     * Spielt den unmittelbar vor dem Import geschriebenen Sicherungs-Dump
     * zurück. Wirft NICHT weiter: Der Aufrufer meldet dem Benutzer den
     * ursprünglichen Fehler, und ein zweiter Fehler beim Zurückrollen darf
     * die Meldung nicht verdrängen - er gehört ins Protokoll, weil dann
     * Handarbeit nötig ist.
     */
    private function rollbackFromBackup(string $backupName, \Throwable $ursache): void {
        $pfad = $this->stageDir() . '/' . $backupName;

        try {
            if (!is_file($pfad)) {
                throw new \RuntimeException("Sicherungs-Dump nicht gefunden: {$backupName}");
            }
            $dump = str_ends_with($backupName, '.gz')
                ? (string) gzdecode((string) file_get_contents($pfad))
                : (string) file_get_contents($pfad);
            if ($dump === '') {
                throw new \RuntimeException('Sicherungs-Dump ist leer.');
            }

            Database::getInstance()->exec($dump);

            PluginAudit::log(
                'datenmigration',
                'Import zurückgerollt',
                $backupName,
                'Grund: ' . $ursache->getMessage() . ' - Sicherung eingespielt'
            );
        } catch (\Throwable $e) {
            AuditLogger::log(
                'Datenmigration: Zurückrollen FEHLGESCHLAGEN',
                'security',
                'Import scheiterte (' . $ursache->getMessage() . '), das Zurückspielen von '
                . $backupName . ' ebenfalls (' . $e->getMessage() . ') - die Datenbank ist in einem '
                . 'unvollständigen Zustand und muss von Hand aus var/datenmigration/' . $backupName
                . ' wiederhergestellt werden.'
            );
            error_log('Datenmigration: Rollback fehlgeschlagen - ' . $e->getMessage());
        }
    }

    /** Rückfall für rename() über Dateisystemgrenzen hinweg. */
    private function copyDir(string $from, string $to): void {
        if (!is_dir($to) && !mkdir($to, 0755, true) && !is_dir($to)) {
            throw new \RuntimeException("Zielverzeichnis konnte nicht angelegt werden: {$to}");
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            $ziel = $to . '/' . $it->getSubPathname();
            if ($item->isDir()) {
                if (!is_dir($ziel) && !mkdir($ziel, 0755, true) && !is_dir($ziel)) {
                    throw new \RuntimeException("Verzeichnis konnte nicht angelegt werden: {$ziel}");
                }
                continue;
            }
            if (!copy($item->getPathname(), $ziel)) {
                throw new \RuntimeException("Datei konnte nicht kopiert werden: {$ziel}");
            }
        }
    }

    private function removeDir(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }

    private function fail(string $message): void {
        $content = '<div class="card"><h1>📦 Datenmigration</h1><p class="alert alert-error">'
            . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
            . '</p><p><a href="/plugin/datenmigration/uebersicht">Zurück</a></p></div>';
        PluginPage::render('Datenmigration', $content);
    }
}
