<?php
// plugins/beispiel-erweiterungspunkte/Ereignisbuch.php
//
// Die eigene Datenhaltung des Lehrbeispiels (Addons#128).
//
// ZWEI TABELLEN, BEIDE MIT DEM PFLICHT-PRAEFIX `plugin_`:
//
//   plugin_beispiel_ereignisse  Jeder ausgeloeste Hook hinterlaesst hier eine
//                               Zeile. Das ist der Grund, warum man diesem
//                               Beispiel beim Arbeiten ZUSEHEN kann - ein
//                               Beispiel, dessen Wirkung man nicht sieht, geht
//                               beim ersten Umbau still kaputt.
//   plugin_beispiel_notizen     Eine redaktionelle Notiz je Pferd bzw. je
//                               Kontakt. Sie ist der Gegenstand, an dem die
//                               Abschnitts-, Such- und Veto-Hooks etwas
//                               Sichtbares tun koennen.
//
// Der Praefix ist keine Konvention, sondern eine Sicherheitsgrenze des Kerns
// (Kern-#338): Ohne ihn weist der Kern die Tabelle im owns-Register der
// plugin.json ab, und die Deinstallation liesse sie liegen. Er verhindert
// zugleich, dass ein Addon `"tables": ["users"]` eintraegt und die
// Deinstallation die Benutzerkonten mitnimmt.

namespace Plugin\BeispielErweiterungspunkte;

use App\Database;
use PDO;

final class Ereignisbuch {

    public const TABELLE_EREIGNISSE = 'plugin_beispiel_ereignisse';
    public const TABELLE_NOTIZEN = 'plugin_beispiel_notizen';

    /** Bezugsarten einer Notiz - Weissliste, nie aus der Anfrage uebernommen. */
    public const TYP_PFERD = 'horse';
    public const TYP_KONTAKT = 'contact';

    /** Einstellungsschluessel - ebenfalls `plugin_`, aus demselben Grund. */
    public const SETTING_SPERRWORT = 'plugin_beispiel_sperrwort';

    /** Vorgabe, solange der Betreiber nichts anderes gesetzt hat. */
    public const SPERRWORT_VORGABE = 'SPERRE';

    /** Deckel gegen ein Ereignisbuch, das zur Textwueste wird. */
    public const ANZEIGE_DECKEL = 60;

    /**
     * Pferde-Notizen dieses Requests, EINMAL geladen.
     *
     * Der Grund steht bei Plugin::katalogAbschnitt(): catalog.card_sections
     * laeuft je Karte, bis zu 24-mal pro Seitenaufruf. Eine Abfrage im
     * Rueckruf waeren 24 Abfragen fuer eine Katalogseite.
     *
     * @var array<int, string>|null
     */
    private static ?array $gedaechtnis = null;

    private function __construct() {}

    /**
     * Idempotentes Schema. Der Kern ruft install() bei jeder (Re-)Aktivierung
     * und nach jedem Addon-Update auf - die Zusicherung lautet "mindestens
     * einmal", nicht "genau einmal".
     *
     * BEWUSST OHNE FREMDSCHLUESSEL auf `horses`/`contacts`. Ein FK mit
     * ON DELETE CASCADE waere der bequemere Weg, aber er greift beim
     * SOFT-Delete nicht - und genau das ist die Lage, die
     * horse.trashed/horse.restored/horse.deleted vorfuehren sollen. Ein echtes
     * Addon darf gern einen FK setzen; es braucht die drei Hooks dann trotzdem
     * fuer den Papierkorb.
     */
    public static function schemaAnlegen(): void {
        $db = Database::getInstance();

        $db->exec(
            'CREATE TABLE IF NOT EXISTS `' . self::TABELLE_EREIGNISSE . '` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `hook` VARCHAR(64) NOT NULL,
                `bezug` VARCHAR(120) DEFAULT NULL,
                `notiz` VARCHAR(255) DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_hook` (`hook`),
                INDEX `idx_bezug` (`bezug`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $db->exec(
            'CREATE TABLE IF NOT EXISTS `' . self::TABELLE_NOTIZEN . '` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `bezug_typ` ENUM(\'horse\', \'contact\') NOT NULL,
                `bezug_id` INT NOT NULL,
                `notiz` VARCHAR(255) NOT NULL,
                `stillgelegt` TINYINT(1) NOT NULL DEFAULT 0,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uniq_bezug` (`bezug_typ`, `bezug_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    // -----------------------------------------------------------------
    // Ereignisse
    // -----------------------------------------------------------------

    /**
     * Haelt einen ausgeloesten Hook fest.
     *
     * Fehler werden verschluckt: Das Ereignisbuch ist eine Lehrhilfe, es darf
     * niemals der Grund sein, warum ein Speichervorgang im Kern scheitert.
     * Derselbe Gedanke wie bei AuditLogger::log() im Kern - ein Protokoll, das
     * den Vorgang scheitern laesst, den es protokollieren soll, waere
     * schlimmer als keines.
     *
     * WAS HIER NICHT HINEINGEHOERT: personenbezogene Inhalte. Diese Tabelle
     * wird von keiner Loeschfrist erfasst; eine E-Mail-Adresse darin
     * ueberlebte jede DSGVO-Loeschung des zugehoerigen Kontakts. Der Bezug
     * ("Kontakt #7") ist in Ordnung, der Inhalt eines Kontaktfelds nicht.
     */
    public static function notieren(string $hook, ?string $bezug = null, ?string $notiz = null): void {
        try {
            $stmt = Database::getInstance()->prepare(
                'INSERT INTO `' . self::TABELLE_EREIGNISSE . '` (`hook`, `bezug`, `notiz`)
                 VALUES (:hook, :bezug, :notiz)'
            );
            $stmt->execute([
                'hook' => self::kurz($hook, 64),
                'bezug' => $bezug === null ? null : self::kurz($bezug, 120),
                'notiz' => $notiz === null ? null : self::kurz($notiz, 255),
            ]);
        } catch (\Throwable $e) {
            // bewusst still - siehe oben.
        }
    }

    /**
     * Die letzten Eintraege, neueste zuerst.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function letzte(int $anzahl = self::ANZEIGE_DECKEL): array {
        $anzahl = max(1, min(self::ANZEIGE_DECKEL, $anzahl));

        try {
            // Der Deckel ist geprueft und ganzzahlig. LIMIT nimmt in MySQL
            // keinen gebundenen Parameter zuverlaessig entgegen; interpoliert
            // wird deshalb ausschliesslich ein (int) aus geprueftem Bereich,
            // nie ein Wert aus der Anfrage.
            $stmt = Database::getInstance()->query(
                'SELECT `hook`, `bezug`, `notiz`, `created_at`
                 FROM `' . self::TABELLE_EREIGNISSE . '`
                 ORDER BY `id` DESC LIMIT ' . (int)$anzahl
            );
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Welche Hooks haben ueberhaupt schon einmal gefeuert? Die Addon-Seite
     * haelt das gegen die Liste der registrierten Hooks - so sieht man auf
     * einen Blick, was man noch nicht ausprobiert hat.
     *
     * @return array<string, int> Hook-Name => Anzahl
     */
    public static function haeufigkeiten(): array {
        try {
            $stmt = Database::getInstance()->query(
                'SELECT `hook`, COUNT(*) AS anzahl
                 FROM `' . self::TABELLE_EREIGNISSE . '` GROUP BY `hook`'
            );
            $ergebnis = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $zeile) {
                $ergebnis[(string)$zeile['hook']] = (int)$zeile['anzahl'];
            }
            return $ergebnis;
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function anzahlGesamt(): int {
        try {
            return (int)Database::getInstance()
                ->query('SELECT COUNT(*) FROM `' . self::TABELLE_EREIGNISSE . '`')
                ->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public static function anzahlFuer(string $bezug): int {
        try {
            $stmt = Database::getInstance()->prepare(
                'SELECT COUNT(*) FROM `' . self::TABELLE_EREIGNISSE . '` WHERE `bezug` = :b'
            );
            $stmt->execute(['b' => $bezug]);
            return (int)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    // -----------------------------------------------------------------
    // Notizen
    // -----------------------------------------------------------------

    /**
     * Die Notiz zu einem Pferd oder Kontakt.
     *
     * @param bool $auchStillgelegt true im Admin-Kontext: Dort will man auch
     *   sehen, was am Datensatz haengt, waehrend er im Papierkorb liegt.
     *   Oeffentlich gilt das Gegenteil - stillgelegt heisst unsichtbar.
     */
    public static function notiz(string $typ, int $bezugId, bool $auchStillgelegt = false): ?string {
        if ($bezugId <= 0 || !self::typGueltig($typ)) {
            return null;
        }

        try {
            $sql = 'SELECT `notiz` FROM `' . self::TABELLE_NOTIZEN . '`
                    WHERE `bezug_typ` = :t AND `bezug_id` = :id';
            if (!$auchStillgelegt) {
                $sql .= ' AND `stillgelegt` = 0';
            }
            $stmt = Database::getInstance()->prepare($sql);
            $stmt->execute(['t' => $typ, 'id' => $bezugId]);
            $wert = $stmt->fetchColumn();
            return $wert === false ? null : (string)$wert;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Wie notiz(), aber aus dem einmal geladenen Gedaechtnis dieses Requests -
     * fuer catalog.card_sections, das je Karte laeuft.
     */
    public static function pferdeNotizAusGedaechtnis(int $horseId): ?string {
        if (self::$gedaechtnis === null) {
            self::$gedaechtnis = [];
            try {
                $stmt = Database::getInstance()->prepare(
                    'SELECT `bezug_id`, `notiz` FROM `' . self::TABELLE_NOTIZEN . '`
                     WHERE `bezug_typ` = :t AND `stillgelegt` = 0'
                );
                $stmt->execute(['t' => self::TYP_PFERD]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $zeile) {
                    self::$gedaechtnis[(int)$zeile['bezug_id']] = (string)$zeile['notiz'];
                }
            } catch (\Throwable $e) {
                // leeres Gedaechtnis - der Katalog zeigt dann eben kein Abzeichen.
            }
        }

        return self::$gedaechtnis[$horseId] ?? null;
    }

    /**
     * Setzt oder loescht die Notiz. Leerer Text loescht - so braucht der
     * Abschnitt im Bearbeitungsformular keinen zweiten Knopf.
     *
     * @return string 'gesetzt' oder 'geloescht' - fuer den Protokolleintrag.
     */
    public static function notizSetzen(string $typ, int $bezugId, string $notiz): string {
        if (!self::typGueltig($typ)) {
            return 'verworfen';
        }
        self::$gedaechtnis = null;

        $db = Database::getInstance();
        $notiz = self::kurz($notiz, 255);

        if ($notiz === '') {
            $stmt = $db->prepare(
                'DELETE FROM `' . self::TABELLE_NOTIZEN . '` WHERE `bezug_typ` = :t AND `bezug_id` = :id'
            );
            $stmt->execute(['t' => $typ, 'id' => $bezugId]);
            return 'geloescht';
        }

        $stmt = $db->prepare(
            'INSERT INTO `' . self::TABELLE_NOTIZEN . '` (`bezug_typ`, `bezug_id`, `notiz`, `stillgelegt`)
             VALUES (:t, :id, :n, 0)
             ON DUPLICATE KEY UPDATE `notiz` = :n2, `stillgelegt` = 0'
        );
        $stmt->execute(['t' => $typ, 'id' => $bezugId, 'n' => $notiz, 'n2' => $notiz]);
        return 'gesetzt';
    }

    /** Stilllegen beim Soft-Delete, wieder in Betrieb beim Wiederherstellen. */
    public static function notizStilllegen(string $typ, int $bezugId, bool $stillgelegt): int {
        self::$gedaechtnis = null;

        try {
            $stmt = Database::getInstance()->prepare(
                'UPDATE `' . self::TABELLE_NOTIZEN . '` SET `stillgelegt` = :s
                 WHERE `bezug_typ` = :t AND `bezug_id` = :id'
            );
            $stmt->execute(['s' => $stillgelegt ? 1 : 0, 't' => $typ, 'id' => $bezugId]);
            return $stmt->rowCount();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** Endgueltig - aufgerufen aus horse.deleted, wo kein CASCADE greift. */
    public static function notizLoeschen(string $typ, int $bezugId): int {
        self::$gedaechtnis = null;

        try {
            $stmt = Database::getInstance()->prepare(
                'DELETE FROM `' . self::TABELLE_NOTIZEN . '` WHERE `bezug_typ` = :t AND `bezug_id` = :id'
            );
            $stmt->execute(['t' => $typ, 'id' => $bezugId]);
            return $stmt->rowCount();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Pferde-Kennungen, deren Notiz den Begriff enthaelt - die Datenquelle des
     * Filters horse.search_ids.
     *
     * Die LIKE-Platzhalter `%` und `_` werden maskiert. Ohne das faende die
     * Eingabe `%` schlicht alles; zwei Addons dieses Repos taten das bis
     * zuletzt nicht.
     *
     * @return array<int, int>
     */
    public static function pferdeMitNotiz(string $begriff): array {
        try {
            $muster = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $begriff) . '%';
            $stmt = Database::getInstance()->prepare(
                'SELECT `bezug_id` FROM `' . self::TABELLE_NOTIZEN . '`
                 WHERE `bezug_typ` = :t AND `stillgelegt` = 0 AND `notiz` LIKE :m'
            );
            $stmt->execute(['t' => self::TYP_PFERD, 'm' => $muster]);
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        } catch (\Throwable $e) {
            // Leere Liste heisst hier "keine Treffer" - und das ist die
            // richtige Antwort auf einen ausdruecklich gestellten Filter, den
            // wir nicht beantworten koennen. Der Aufrufer zeigte sonst den
            // vollen Bestand, obwohl der Benutzer etwas anderes meinte.
            return [];
        }
    }

    private static function typGueltig(string $typ): bool {
        return $typ === self::TYP_PFERD || $typ === self::TYP_KONTAKT;
    }

    // -----------------------------------------------------------------
    // Einstellung
    // -----------------------------------------------------------------

    /** Das Wort, das in einer Notiz die Veroeffentlichung blockiert. */
    public static function sperrwort(): string {
        try {
            $stmt = Database::getInstance()->prepare(
                'SELECT `setting_value` FROM `settings` WHERE `setting_key` = :k'
            );
            $stmt->execute(['k' => self::SETTING_SPERRWORT]);
            $wert = $stmt->fetchColumn();
            $wert = $wert === false ? '' : trim((string)$wert);
            return $wert !== '' ? $wert : self::SPERRWORT_VORGABE;
        } catch (\Throwable $e) {
            return self::SPERRWORT_VORGABE;
        }
    }

    public static function sperrwortSetzen(string $wort): string {
        $wort = self::kurz($wort, 40);
        if ($wort === '') {
            $wort = self::SPERRWORT_VORGABE;
        }

        $stmt = Database::getInstance()->prepare(
            'INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE `setting_value` = :v2'
        );
        $stmt->execute(['k' => self::SETTING_SPERRWORT, 'v' => $wort, 'v2' => $wort]);
        return $wort;
    }

    /**
     * Raeumt Einstellungen weg, die dieses Addon verursacht, aber nicht selbst
     * benannt hat - siehe Plugin::uninstall(). Sie beginnen nicht mit
     * `plugin_` und duerfen deshalb nicht im owns-Register stehen.
     *
     * @param array<int, string> $schluessel
     */
    public static function kernEinstellungenAufraeumen(array $schluessel): int {
        $entfernt = 0;
        try {
            $stmt = Database::getInstance()->prepare('DELETE FROM `settings` WHERE `setting_key` = :k');
            foreach ($schluessel as $k) {
                $stmt->execute(['k' => $k]);
                $entfernt += $stmt->rowCount();
            }
        } catch (\Throwable $e) {
            // Die Deinstallation darf daran nicht scheitern.
        }
        return $entfernt;
    }

    // -----------------------------------------------------------------

    /** Einzeilig und gedeckelt - alles, was in eine VARCHAR-Spalte soll. */
    public static function kurz(string $text, int $laenge = 120): string {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        return mb_substr($text, 0, $laenge);
    }
}
