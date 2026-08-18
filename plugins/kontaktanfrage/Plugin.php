<?php
// kontaktanfrage/Plugin.php
//
// Addon für Hengstverzeichnis_Framework, löst Addons#106: Ein Besucher kann
// eine Person oder eine Deckstation kontaktieren, OHNE deren Adresse zu
// sehen. Das Formular fragt genau drei Angaben ab - E-Mail, Name und einen
// Grund aus fester Auswahl.
//
// Die drei tragenden Entscheidungen, die man dem Code sonst nicht ansieht:
//
//  1. Zugestellt wird IMMER an eine im Addon hinterlegte Team-Adresse, nie
//     direkt an die angefragte Person. Das Team prüft und fragt die Person,
//     ob Kontakt überhaupt gewünscht ist. Ein Formular, das ungeprüft an eine
//     fremde Adresse zustellt, ist ein Spam-Relais mit dem Verzeichnis als
//     Adressbuch.
//  2. Der Grund kommt aus einer festen Liste, es gibt KEIN Freitextfeld. Nur
//     so lässt sich die Nachricht serverseitig zusammensetzen; vom Absender
//     kommt ausschließlich, was geprüft werden kann. Wer später "nur ein
//     kleines Bemerkungsfeld" ergänzt, gibt genau diesen Schutz auf.
//  3. Opt-out statt Opt-in: Kontaktanfragen sind erlaubt und lassen sich je
//     Datensatz abschalten. Das Kennzeichen gehört dem ADDON (eigene
//     Tabelle) - der Kern bekommt dafür keine Spalte, gepflegt wird es über
//     person.edit_sections/station.edit_sections.
//
// Installation (lokal im Framework-Repo):
//   cp -r kontaktanfrage plugins/kontaktanfrage
// Danach unter Admin -> Plugins verwalten (/admin/plugins) aktivieren und
// unter Admin -> Kontaktanfragen die Team-Adresse hinterlegen.

namespace Plugin\Kontaktanfrage;

use App\Controllers\BaseController;
use App\Database;
use App\Plugin\HookManager;
use App\Plugin\PluginPage;
use App\Router;
use App\Security\ClientIp;
use App\Security\RateLimiter;
use App\Service\AuditLogger;
use App\Service\Mailer;
use App\Service\Scheduler;
use PDO;

class Plugin {

    public function register(HookManager $hooks): void {
        $hooks->addFilter('person.detail_sections', [$this, 'personenAbschnitt']);
        $hooks->addFilter('station.detail_sections', [$this, 'stationsAbschnitt']);
        $hooks->addFilter('person.edit_sections', [$this, 'personenOptOut']);
        $hooks->addFilter('station.edit_sections', [$this, 'stationsOptOut']);
        $hooks->addFilter('admin.dashboard_tiles', [$this, 'dashboardKachel']);

        // Aufbewahrungsfrist (DSGVO): Die eingegebene E-Mail-Adresse ist ein
        // personenbezogenes Datum, sie darf nicht unbegrenzt liegenbleiben.
        // Registriert wird nur die Aufgabe - ausgeführt wird sie erst, wenn
        // der Cron-Lauf des Kerns (/admin/cron bzw. CronController) sie als
        // fällig erkennt.
        Scheduler::register('kontaktanfrage.aufraeumen', 86400, static function (): void {
            Aufraeumen::faellige();
        });
    }

    /**
     * Framework-Hook (#75): Der PluginManager ruft install() bei jeder
     * Aktivierung und nach jedem Addon-Update auf - deshalb idempotent, und
     * deshalb steht das DDL hier und nicht in register().
     */
    public function install(): void {
        $db = Database::getInstance();

        // Bewusst keine Fremdschlüssel auf persons/breeding_stations: Das Ziel
        // ist polymorph (target_type + target_id), ein FK kann nur auf genau
        // eine Tabelle zeigen. Verwaiste Zeilen räumt Aufraeumen::faellige()
        // weg, die Verwaltung zeigt sie bis dahin als "Datensatz entfernt".
        $db->exec(
            'CREATE TABLE IF NOT EXISTS `plugin_kontaktanfrage_requests` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `target_type` VARCHAR(10) NOT NULL,
                `target_id` INT NOT NULL,
                `reason_key` VARCHAR(64) NOT NULL,
                `reason_label` VARCHAR(100) NOT NULL,
                `requester_name` VARCHAR(150) NOT NULL,
                `requester_email` VARCHAR(150) NOT NULL,
                `team_notified` TINYINT(1) NOT NULL DEFAULT 0,
                `forwarded_at` DATETIME NULL DEFAULT NULL,
                `forwarded_by` VARCHAR(100) NULL DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_ka_ziel` (`target_type`, `target_id`),
                INDEX `idx_ka_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        // Das Opt-out ist die ANWESENHEIT einer Zeile. Kein Wahrheitswert, den
        // jemand auf 0 setzt: Der Normalfall (Anfragen erlaubt) erzeugt damit
        // gar keine Daten, und ein "wieder erlauben" hinterlässt keine Zeile,
        // die später jemand für ein Opt-out hält.
        $db->exec(
            'CREATE TABLE IF NOT EXISTS `plugin_kontaktanfrage_optout` (
                `target_type` VARCHAR(10) NOT NULL,
                `target_id` INT NOT NULL,
                `disabled_by` VARCHAR(100) NULL DEFAULT NULL,
                `disabled_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`target_type`, `target_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        // Eigene Konfigurationstabelle statt der Kern-Tabelle `settings`:
        // deren Schlüsselspalte ist VARCHAR(50), und die Systemeinstellungen
        // des Kerns sind eine redaktionell gepflegte Oberfläche, in die ein
        // Addon keine Fremdschlüssel streuen sollte.
        $db->exec(
            'CREATE TABLE IF NOT EXISTS `plugin_kontaktanfrage_config` (
                `config_key` VARCHAR(64) NOT NULL PRIMARY KEY,
                `config_value` TEXT NULL,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /**
     * @param array<int, string> $sections
     * @param array<string, mixed> $person
     * @param array<string, mixed> $horsesByRole
     * @return array<int, string>
     */
    public function personenAbschnitt(array $sections, array $person, array $horsesByRole): array {
        $html = Formular::bauen(Ziel::PERSON, $person);
        if ($html !== '') {
            $sections[] = $html;
        }
        return $sections;
    }

    /**
     * @param array<int, string> $sections
     * @param array<string, mixed> $station
     * @param array<int, array<string, mixed>> $horses
     * @return array<int, string>
     */
    public function stationsAbschnitt(array $sections, array $station, array $horses): array {
        $html = Formular::bauen(Ziel::STATION, $station);
        if ($html !== '') {
            $sections[] = $html;
        }
        return $sections;
    }

    /**
     * @param array<int, string> $sections
     * @param array<string, mixed> $person
     * @return array<int, string>
     */
    public function personenOptOut(array $sections, array $person): array {
        $sections[] = Formular::optOutAbschnitt(Ziel::PERSON, $person);
        return $sections;
    }

    /**
     * @param array<int, string> $sections
     * @param array<string, mixed> $station
     * @return array<int, string>
     */
    public function stationsOptOut(array $sections, array $station): array {
        $sections[] = Formular::optOutAbschnitt(Ziel::STATION, $station);
        return $sections;
    }

    /**
     * @param array<int, array{url:string,label:string,icon:string}> $tiles
     * @return array<int, array{url:string,label:string,icon:string}>
     */
    public function dashboardKachel(array $tiles): array {
        $tiles[] = [
            'url' => '/plugin/kontaktanfrage/verwaltung',
            'label' => 'Kontaktanfragen',
            'icon' => '✉️',
        ];
        return $tiles;
    }

    /**
     * @return array<int, array{module:string, action:string, label:string, module_label:string}>
     */
    public function permissions(): array {
        return [
            [
                'module' => 'kontaktanfrage',
                'action' => 'manage',
                'label' => 'Anfragen einsehen, weiterleiten und löschen',
                'module_label' => 'Kontaktanfragen',
            ],
        ];
    }

    /**
     * @return array<int, array{method:string, path:string, callback:array}>
     */
    public function routes(): array {
        return [
            ['method' => 'POST', 'path' => '/senden', 'callback' => [AnfrageController::class, 'senden']],
            ['method' => 'GET', 'path' => '/verwaltung', 'callback' => [VerwaltungController::class, 'index']],
            ['method' => 'POST', 'path' => '/verwaltung/einstellungen', 'callback' => [VerwaltungController::class, 'einstellungen']],
            ['method' => 'POST', 'path' => '/verwaltung/weiterleiten', 'callback' => [VerwaltungController::class, 'weiterleiten']],
            ['method' => 'POST', 'path' => '/verwaltung/loeschen', 'callback' => [VerwaltungController::class, 'loeschen']],
            ['method' => 'POST', 'path' => '/verwaltung/aufraeumen', 'callback' => [VerwaltungController::class, 'aufraeumen']],
            ['method' => 'POST', 'path' => '/opt-out', 'callback' => [OptOutController::class, 'speichern']],
        ];
    }
}

/**
 * Eingabeprüfung ohne Datenbank und ohne Framework - reine Funktionen, die
 * sich als Unit-Test festnageln lassen (tests/Unit/KontaktanfrageEingabeTest.php).
 */
final class Eingabe {

    /** Passt in requester_email VARCHAR(150). */
    public const EMAIL_MAX = 150;

    private function __construct() {}

    /**
     * Liefert die geprüfte Adresse oder null. Kein bool-Rückgabewert: Der
     * Aufrufer soll mit der GEPRÜFTEN Fassung weiterarbeiten, nicht mit der
     * Eingabe, die er gerade hat prüfen lassen.
     */
    public static function email(string $roh): ?string {
        // CR/LF wird VOR dem Trimmen geprüft, und das ist der ganze Punkt:
        // trim() entfernt einen angehängten Zeilenumbruch und macht die
        // Adresse damit gültig. Eine Adresse, die mit Zeilenumbruch ankommt,
        // ist aber keine Eingabe eines Menschen, sondern der Anlauf, eine
        // zweite Kopfzeile in die Mail zu bekommen (Header-Injection). Der
        // Mailer des Kerns lehnt CR/LF ebenfalls ab - das ist die zweite
        // Verteidigungslinie, nicht die erste.
        if (preg_match('/[\r\n]/', $roh)) {
            return null;
        }

        $wert = trim($roh);
        if ($wert === '' || strlen($wert) > self::EMAIL_MAX) {
            return null;
        }

        $geprueft = filter_var($wert, FILTER_VALIDATE_EMAIL);
        return is_string($geprueft) ? $geprueft : null;
    }

    /**
     * Macht aus beliebiger Eingabe eine einzeilige, gekürzte Zeichenkette:
     * Steuerzeichen raus, Whitespace-Folgen zu einem Leerzeichen, getrimmt.
     *
     * Gebraucht für alles, was in eine Betreffzeile oder eine VARCHAR-Spalte
     * geht. Bei ungültigem UTF-8 liefert preg_replace null - daraus wird hier
     * die leere Zeichenkette, und die weisen die Aufrufer als fehlende Angabe
     * ab. Lieber eine abgelehnte Anfrage als ein halb dekodierter Name in
     * einer Mail.
     */
    public static function einzeilig(string $roh, int $maxLaenge): string {
        $ohneSteuerzeichen = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $roh);
        $zusammengefasst = (string) preg_replace('/\s+/u', ' ', $ohneSteuerzeichen);
        return mb_substr(trim($zusammengefasst), 0, $maxLaenge);
    }
}

/**
 * Die Gründe-Auswahl. Vier Standardgründe im Code, beliebig viele weitere
 * aus den Addon-Einstellungen - aber immer eine ABGESCHLOSSENE Liste, gegen
 * die serverseitig geprüft wird.
 */
final class Gruende {

    /** @var array<string, string> */
    public const STANDARD = [
        'deckanfrage' => 'Deckanfrage',
        'kaufinteresse' => 'Kaufinteresse',
        'abstammung' => 'Frage zur Abstammung',
        'sonstiges' => 'Sonstiges',
    ];

    /** Deckel gegen eine Einstellungsseite, die zur Textdatei wird. */
    public const MAX_ZUSATZ = 30;

    /** Passt in reason_label VARCHAR(100). */
    public const LABEL_MAX = 100;

    private function __construct() {}

    /**
     * Leitet den gespeicherten Schlüssel aus dem Anzeigetext ab. Bewusst
     * deterministisch statt fortlaufend nummeriert: Eine Nummer verschöbe
     * sich, sobald der Admin eine Zeile einfügt oder umsortiert, und alte
     * Anfragen zeigten plötzlich einen anderen Grund. Der Anzeigetext wird
     * zusätzlich in der Anfrage mitgespeichert, damit auch ein später
     * umbenannter Grund lesbar bleibt.
     */
    public static function schluessel(string $label): string {
        $wert = mb_strtolower(Eingabe::einzeilig($label, self::LABEL_MAX));
        $wert = strtr($wert, [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
            'å' => 'aa', 'æ' => 'ae', 'ø' => 'oe',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'á' => 'a', 'à' => 'a', 'â' => 'a',
        ]);
        $wert = (string) preg_replace('/[^a-z0-9]+/', '-', $wert);
        return mb_substr(trim($wert, '-'), 0, 64);
    }

    /**
     * Zerlegt die Admin-Eingabe (ein Grund je Zeile) in Schlüssel => Text.
     *
     * @return array<string, string>
     */
    public static function ausText(string $text): array {
        $gruende = [];

        foreach (preg_split('/\R/u', $text) ?: [] as $zeile) {
            $label = Eingabe::einzeilig($zeile, self::LABEL_MAX);
            if ($label === '') {
                continue;
            }

            $schluessel = self::schluessel($label);
            // Eine Zeile aus reiner Interpunktion ("---") ergibt keinen
            // Schlüssel und wäre sonst ein Grund ohne Bezeichner.
            if ($schluessel === '' || isset($gruende[$schluessel]) || isset(self::STANDARD[$schluessel])) {
                continue;
            }

            $gruende[$schluessel] = $label;
            if (count($gruende) >= self::MAX_ZUSATZ) {
                break;
            }
        }

        return $gruende;
    }

    /**
     * Die vollständige gültige Liste: Standardgründe zuerst, danach die
     * ergänzten. Genau diese Liste ist der Maßstab der serverseitigen Prüfung.
     *
     * @return array<string, string>
     */
    public static function alle(string $zusatzText): array {
        return self::STANDARD + self::ausText($zusatzText);
    }

    /**
     * @param array<string, string> $liste
     */
    public static function istGueltig(string $schluessel, array $liste): bool {
        return $schluessel !== '' && array_key_exists($schluessel, $liste);
    }
}

/**
 * Ziel einer Anfrage: eine Person oder eine Deckstation. Der Typ ist an
 * jeder Stelle eine von genau zwei Zeichenketten - Tabellennamen und Routen
 * werden daraus über feste Zuordnungen abgeleitet, nie zusammengesetzt.
 */
final class Ziel {

    public const PERSON = 'person';
    public const STATION = 'station';

    private function __construct() {}

    public static function ausAnfrage(mixed $roh): ?string {
        if (!is_string($roh)) {
            return null;
        }
        $wert = trim($roh);
        return ($wert === self::PERSON || $wert === self::STATION) ? $wert : null;
    }

    public static function bezeichnung(string $typ): string {
        return $typ === self::PERSON ? 'Person' : 'Deckstation';
    }

    /** Kern-Modul, dessen Bearbeiten-Recht das Opt-out schützt. */
    public static function modul(string $typ): string {
        return $typ === self::PERSON ? 'persons' : 'breeding_stations';
    }

    public static function oeffentlicherLink(string $typ, int $id): string {
        return ($typ === self::PERSON ? '/person?id=' : '/station?id=') . $id;
    }

    public static function bearbeitenLink(string $typ, int $id): string {
        return ($typ === self::PERSON ? '/admin/persons/edit?id=' : '/admin/breeding-stations/edit?id=') . $id;
    }

    /**
     * Der Zieldatensatz unter derselben Sichtbarkeitsregel wie die
     * öffentliche Seite (veröffentlicht, nicht im Papierkorb). Anzeige und
     * Verarbeitung müssen dieselbe Regel anwenden - sonst liefe ein direkter
     * POST mit gültigem CSRF-Token an einem bewusst unveröffentlichten
     * Datensatz vorbei.
     *
     * @return array{id:int, name:string, email:?string}|null
     */
    public static function oeffentlich(string $typ, int $id): ?array {
        return self::laden($typ, $id, true);
    }

    /**
     * Wie oben, ohne is_published-Filter - für das Backend und die
     * Weiterleitung: Eine Anfrage bleibt bearbeitbar, auch wenn der
     * Datensatz zwischenzeitlich aus der Veröffentlichung genommen wurde.
     * Ein Datensatz im Papierkorb ist dagegen auch hier keiner mehr.
     *
     * @return array{id:int, name:string, email:?string}|null
     */
    public static function intern(string $typ, int $id): ?array {
        return self::laden($typ, $id, false);
    }

    /**
     * @return array{id:int, name:string, email:?string}|null
     */
    private static function laden(string $typ, int $id, bool $nurVeroeffentlicht): ?array {
        if ($id < 1) {
            return null;
        }

        $tabelle = $typ === self::PERSON ? 'persons' : 'breeding_stations';
        $sql = "SELECT id, name, email FROM `{$tabelle}` WHERE id = ? AND deleted_at IS NULL";
        if ($nurVeroeffentlicht) {
            $sql .= ' AND is_published = 1';
        }

        $stmt = Database::getInstance()->prepare($sql);
        $stmt->execute([$id]);
        $zeile = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$zeile) {
            return null;
        }

        $email = Eingabe::email((string) ($zeile['email'] ?? ''));
        return [
            'id' => (int) $zeile['id'],
            'name' => (string) $zeile['name'],
            'email' => $email,
        ];
    }

    /** True, wenn für diesen Datensatz Kontaktanfragen abgeschaltet wurden. */
    public static function abgeschaltet(string $typ, int $id): bool {
        try {
            $stmt = Database::getInstance()->prepare(
                'SELECT 1 FROM `plugin_kontaktanfrage_optout` WHERE target_type = ? AND target_id = ?'
            );
            $stmt->execute([$typ, $id]);
            return $stmt->fetchColumn() !== false;
        } catch (\Throwable $e) {
            // Tabelle fehlt (Aktivierung läuft gerade): fail-closed, lieber
            // kein Formular als eines, das ein Opt-out übergeht.
            return true;
        }
    }
}

/**
 * Addon-Einstellungen in eigener Tabelle. Der Cache lebt genau einen
 * Request - der Formular-Hook fragt sie auf jeder Personen- und
 * Stationsseite ab.
 */
final class Konfiguration {

    public const STANDARD_AUFBEWAHRUNG_TAGE = 180;
    public const MAX_AUFBEWAHRUNG_TAGE = 3650;

    /** @var array<string, string>|null */
    private static ?array $cache = null;

    private function __construct() {}

    /** @return array<string, string> */
    public static function alle(): array {
        if (self::$cache === null) {
            try {
                $rows = Database::getInstance()
                    ->query('SELECT config_key, config_value FROM `plugin_kontaktanfrage_config`')
                    ->fetchAll(PDO::FETCH_KEY_PAIR);
                self::$cache = is_array($rows) ? $rows : [];
            } catch (\Throwable $e) {
                self::$cache = [];
            }
        }
        return self::$cache;
    }

    /**
     * Die Adresse, an die JEDE Anfrage geht. Ohne sie erscheint kein
     * Formular - eine Anfrage, die nirgends ankommt, ist schlimmer als kein
     * Formular, weil der Besucher sie für zugestellt hält.
     */
    public static function teamAdresse(): ?string {
        return Eingabe::email((string) (self::alle()['team_email'] ?? ''));
    }

    public static function zusatzGruendeText(): string {
        return (string) (self::alle()['zusatz_gruende'] ?? '');
    }

    /** @return array<string, string> */
    public static function gruende(): array {
        return Gruende::alle(self::zusatzGruendeText());
    }

    /** 0 bedeutet: keine automatische Löschung (der Admin räumt von Hand auf). */
    public static function aufbewahrungstage(): int {
        $wert = self::alle()['aufbewahrung_tage'] ?? null;
        if ($wert === null || $wert === '') {
            return self::STANDARD_AUFBEWAHRUNG_TAGE;
        }
        $tage = filter_var($wert, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => self::MAX_AUFBEWAHRUNG_TAGE]]);
        return is_int($tage) ? $tage : self::STANDARD_AUFBEWAHRUNG_TAGE;
    }

    public static function setzen(string $schluessel, string $wert): void {
        $stmt = Database::getInstance()->prepare(
            'INSERT INTO `plugin_kontaktanfrage_config` (config_key, config_value)
             VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE config_value = :v2'
        );
        $stmt->execute(['k' => $schluessel, 'v' => $wert, 'v2' => $wert]);
        self::$cache = null;
    }
}

/**
 * Baut die HTML-Fragmente der vier Abschnitts-Hooks. Farben ausschließlich
 * über Theme-Variablen, Fremddaten ausschließlich escaped - beide
 * Abschnitte werden vom Kern unescaped ausgegeben.
 */
final class Formular {

    private function __construct() {}

    /**
     * Öffentliches Kontaktformular. Leere Zeichenkette bedeutet "kein
     * Abschnitt" - der Hook fügt dann nichts hinzu.
     *
     * @param array<string, mixed> $ziel Payload des jeweiligen detail_sections-Hooks
     */
    public static function bauen(string $typ, array $ziel): string {
        $id = isset($ziel['id']) ? (int) $ziel['id'] : 0;
        if ($id < 1 || Konfiguration::teamAdresse() === null || Ziel::abgeschaltet($typ, $id)) {
            return '';
        }

        $gruende = Konfiguration::gruende();
        if ($gruende === []) {
            return '';
        }

        $html = '<h3 style="margin-top:0;">✉️ Kontakt aufnehmen</h3>';
        $html .= self::rueckmeldung();
        $html .= '<p style="color:var(--text-muted);font-size:0.9em;">Ihre Anfrage geht an das Team des Verzeichnisses, nicht direkt an '
            . ($typ === Ziel::PERSON ? 'die Person' : 'die Deckstation')
            . '. Das Team prüft sie und leitet sie weiter, wenn Kontakt gewünscht ist.</p>';

        $html .= '<form method="POST" action="/plugin/kontaktanfrage/senden">';
        $html .= '<input type="hidden" name="csrf_token" value="'
            . htmlspecialchars(Router::generateCsrfToken(), ENT_QUOTES, 'UTF-8') . '">';
        $html .= '<input type="hidden" name="ziel_typ" value="' . htmlspecialchars($typ, ENT_QUOTES, 'UTF-8') . '">';
        $html .= '<input type="hidden" name="ziel_id" value="' . $id . '">';

        // Honeypot: für Menschen unsichtbar. Füllt ein Bot es aus, meldet der
        // Controller scheinbar Erfolg und verwirft die Anfrage - ohne dem Bot
        // zu verraten, woran es lag.
        $html .= '<div style="position:absolute;left:-9999px;" aria-hidden="true">'
            . '<label for="kontaktanfrage-webseite">Webseite (bitte leer lassen)</label>'
            . '<input type="text" id="kontaktanfrage-webseite" name="webseite" tabindex="-1" autocomplete="off">'
            . '</div>';

        $html .= '<div class="form-group"><label for="kontaktanfrage-grund">Grund der Anfrage</label>'
            . '<select class="form-control" id="kontaktanfrage-grund" name="grund" required>';
        foreach ($gruende as $schluessel => $label) {
            $html .= '<option value="' . htmlspecialchars($schluessel, ENT_QUOTES, 'UTF-8') . '">'
                . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
        }
        $html .= '</select></div>';

        $html .= '<div class="form-group"><label for="kontaktanfrage-name">Ihr Name</label>'
            . '<input class="form-control" type="text" id="kontaktanfrage-name" name="name" maxlength="150" required></div>';
        $html .= '<div class="form-group"><label for="kontaktanfrage-email">Ihre E-Mail-Adresse</label>'
            . '<input class="form-control" type="email" id="kontaktanfrage-email" name="email" maxlength="150" required></div>';

        $html .= '<p style="color:var(--text-muted);font-size:0.85em;">Es gibt bewusst kein Nachrichtenfeld - '
            . 'der Grund aus der Auswahl genügt für die erste Kontaktaufnahme. Name und E-Mail-Adresse werden '
            . 'gespeichert, damit das Team die Anfrage bearbeiten kann.</p>';
        $html .= '<button type="submit" class="btn">Anfrage absenden</button>';
        $html .= '</form>';

        return $html;
    }

    /**
     * Opt-out-Abschnitt im Admin-Bearbeitungsformular. Eigenes Formular mit
     * eigener Route und eigener Berechtigungsprüfung - der Speichern-Knopf
     * des Kerns speichert Plugin-Felder nicht mit (siehe
     * docs/plugin-development.md, Abschnitt zu edit_sections).
     *
     * @param array<string, mixed> $datensatz
     */
    public static function optOutAbschnitt(string $typ, array $datensatz): string {
        $id = isset($datensatz['id']) ? (int) $datensatz['id'] : 0;
        if ($id < 1) {
            return '';
        }

        $abgeschaltet = Ziel::abgeschaltet($typ, $id);
        $bezeichnung = Ziel::bezeichnung($typ);

        $html = '<h2 style="margin-top:0;font-size:1.1rem;">✉️ Kontaktanfragen</h2>';
        $html .= '<p style="color:var(--text-muted);font-size:0.9em;">Besucher können über die öffentliche Seite '
            . 'eine Anfrage an das Team stellen, ohne die Adresse dieser ' . htmlspecialchars($bezeichnung, ENT_QUOTES, 'UTF-8')
            . ' zu sehen. Das ist die Vorgabe und lässt sich hier für diesen Datensatz abschalten.</p>';

        $html .= '<form method="POST" action="/plugin/kontaktanfrage/opt-out">';
        $html .= '<input type="hidden" name="csrf_token" value="'
            . htmlspecialchars(Router::generateCsrfToken(), ENT_QUOTES, 'UTF-8') . '">';
        $html .= '<input type="hidden" name="ziel_typ" value="' . htmlspecialchars($typ, ENT_QUOTES, 'UTF-8') . '">';
        $html .= '<input type="hidden" name="ziel_id" value="' . $id . '">';
        // Verstecktes Feld vor der Checkbox: Eine nicht angehakte Checkbox
        // wird gar nicht übertragen, ohne den Vorgabewert wäre "abschalten"
        // von "Formular ohne dieses Feld" nicht zu unterscheiden.
        $html .= '<input type="hidden" name="erlaubt" value="0">';
        $html .= '<label style="display:flex;gap:0.5rem;align-items:center;">'
            . '<input type="checkbox" name="erlaubt" value="1"' . ($abgeschaltet ? '' : ' checked') . '>'
            . '<span>Kontaktanfragen über das Formular zulassen</span></label>';
        $html .= '<p style="color:var(--text-muted);font-size:0.85em;margin-top:0.5rem;">Änderungen an den Stammdaten oben '
            . 'bitte zuerst speichern - dieser Knopf speichert ausschließlich diese Einstellung.</p>';
        $html .= '<button type="submit" class="btn">Kontaktanfragen-Einstellung übernehmen</button>';
        $html .= '</form>';

        return $html;
    }

    /** Rückmeldung nach dem Absenden, gesteuert über einen festen Satz Werte. */
    private static function rueckmeldung(): string {
        $status = is_string($_GET['kontaktanfrage'] ?? null) ? $_GET['kontaktanfrage'] : '';

        $texte = [
            'erfolg' => ['Ihre Anfrage wurde an das Team übermittelt. Es meldet sich, sobald geklärt ist, ob Kontakt gewünscht ist.', 'success'],
            'fehler' => ['Ihre Anfrage konnte nicht übermittelt werden. Bitte prüfen Sie Ihre Angaben und versuchen Sie es später erneut.', 'danger'],
            'zuviele' => ['Es sind zu viele Anfragen in kurzer Zeit eingegangen. Bitte versuchen Sie es später erneut.', 'danger'],
        ];

        if (!isset($texte[$status])) {
            return '';
        }

        [$text, $art] = $texte[$status];
        return '<p style="color:var(--' . $art . '-fg);background:var(--' . $art . '-soft-bg);padding:0.6rem;'
            . 'border-radius:var(--border-radius, 4px);">' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</p>';
    }
}

/**
 * Baut die beiden Nachrichten. Getrennt vom Versand, damit der Text an einer
 * Stelle steht und nicht in zwei Controllern auseinanderdriftet.
 */
final class Nachricht {

    private function __construct() {}

    /** Betreffzeilen sind Kopfzeilen - hier endet jede Mehrzeiligkeit. */
    public static function betreff(string $rohtext): string {
        return Eingabe::einzeilig($rohtext, 150);
    }

    /**
     * @param array{id:int, name:string, email:?string} $ziel
     */
    public static function anTeam(string $typ, array $ziel, string $grundLabel, string $name, string $email, string $seitenname, string $basisUrl): string {
        $e = static fn(string $wert): string => htmlspecialchars($wert, ENT_QUOTES, 'UTF-8');

        return '<p>Über ' . $e($seitenname) . ' ist eine Kontaktanfrage eingegangen.</p>'
            . '<p><strong>Ziel:</strong> ' . $e(Ziel::bezeichnung($typ)) . ' ' . $e($ziel['name']) . '<br>'
            . '<strong>Grund:</strong> ' . $e($grundLabel) . '<br>'
            . '<strong>Name des Anfragenden:</strong> ' . $e($name) . '<br>'
            . '<strong>E-Mail des Anfragenden:</strong> ' . $e($email) . '</p>'
            . '<p>Bitte prüfen und - wenn Kontakt gewünscht ist - weiterleiten: '
            . $e(rtrim($basisUrl, '/') . '/plugin/kontaktanfrage/verwaltung') . '</p>';
    }

    /**
     * @param array{id:int, name:string, email:?string} $ziel
     */
    public static function anEmpfaenger(string $typ, array $ziel, string $grundLabel, string $name, string $email, string $seitenname): string {
        $e = static fn(string $wert): string => htmlspecialchars($wert, ENT_QUOTES, 'UTF-8');

        return '<p>Hallo ' . $e($ziel['name']) . ',</p>'
            . '<p>über ' . $e($seitenname) . ' ist eine Kontaktanfrage für Sie eingegangen. '
            . 'Das Team hat sie geprüft und leitet sie hiermit weiter.</p>'
            . '<p><strong>Grund:</strong> ' . $e($grundLabel) . '<br>'
            . '<strong>Name:</strong> ' . $e($name) . '<br>'
            . '<strong>E-Mail:</strong> ' . $e($email) . '</p>'
            . '<p>Sie können direkt an die genannte Adresse antworten. Ihre eigene Adresse wurde dem Anfragenden '
            . 'nicht angezeigt. Wenn Sie künftig keine Kontaktanfragen mehr erhalten möchten, sagen Sie dem Team '
            . 'Bescheid - die Anfragen lassen sich für Ihren Datensatz abschalten.</p>';
    }
}

/**
 * Löschroutine der Aufbewahrungsfrist. Läuft über den Scheduler des Kerns
 * und zusätzlich per Knopf in der Verwaltung - dieselbe Methode, damit sich
 * "automatisch" und "von Hand" nicht unterschiedlich verhalten.
 */
final class Aufraeumen {

    private function __construct() {}

    /** @return int Anzahl gelöschter Anfragen */
    public static function faellige(): int {
        $tage = Konfiguration::aufbewahrungstage();
        $db = Database::getInstance();
        $geloescht = 0;

        if ($tage > 0) {
            $stmt = $db->prepare(
                'DELETE FROM `plugin_kontaktanfrage_requests`
                 WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)'
            );
            $stmt->execute([$tage]);
            $geloescht = $stmt->rowCount();
        }

        // Opt-outs endgültig gelöschter Datensätze. Weich gelöschte bleiben
        // erhalten (die Zeile existiert weiter, deleted_at ist gesetzt) -
        // eine Wiederherstellung soll das Opt-out nicht verlieren.
        $db->exec(
            "DELETE o FROM `plugin_kontaktanfrage_optout` o
             LEFT JOIN persons p ON o.target_type = 'person' AND p.id = o.target_id
             LEFT JOIN breeding_stations s ON o.target_type = 'station' AND s.id = o.target_id
             WHERE p.id IS NULL AND s.id IS NULL"
        );

        if ($geloescht > 0) {
            AuditLogger::log(
                'Kontaktanfragen nach Aufbewahrungsfrist gelöscht',
                'kontaktanfrage',
                "{$geloescht} Anfrage(n) älter als {$tage} Tage entfernt",
                null,
                'SYSTEM'
            );
        }

        return $geloescht;
    }
}

/**
 * Öffentlicher POST-Endpunkt. Bewusst ohne checkAuth() - er ist für anonyme
 * Besucher gedacht, wie das DSGVO-Kontaktformular des Kerns. Der Schutz
 * besteht deshalb aus vier Hürden: CSRF-Token, Honeypot, Rate-Limit (je IP
 * UND je Empfänger) und der abgeschlossenen Gründe-Liste.
 */
class AnfrageController extends BaseController {

    private const MAX_JE_IP = 5;
    private const FENSTER_IP = 3600;
    private const MAX_JE_ZIEL = 10;
    private const FENSTER_ZIEL = 86400;

    public function senden(): void {
        if (!Router::verifyCsrfToken(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }

        $typ = Ziel::ausAnfrage($_POST['ziel_typ'] ?? null);
        $id = filter_var($_POST['ziel_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($typ === null || !is_int($id)) {
            header('Location: /katalog');
            exit;
        }

        // Honeypot ausgefüllt: verwerfen und Erfolg melden.
        if (!empty($_POST['webseite'])) {
            $this->zurueck($typ, $id, 'erfolg');
        }

        $ip = ClientIp::resolve();
        $zielBezeichner = $typ . ':' . $id;
        // Zwei Zähler, weil sie zwei verschiedene Missbräuche treffen: Der
        // IP-Zähler bremst den einzelnen Absender, der Empfänger-Zähler
        // verhindert, dass eine Person über wechselnde Anschlüsse zugemüllt
        // wird. Nur einer von beiden wäre jeweils leicht zu umgehen.
        if (RateLimiter::tooManyAttempts($ip, 'kontaktanfrage-ip', self::MAX_JE_IP, self::FENSTER_IP)
            || RateLimiter::tooManyAttempts($zielBezeichner, 'kontaktanfrage-ziel', self::MAX_JE_ZIEL, self::FENSTER_ZIEL)) {
            AuditLogger::log(
                'Kontaktanfrage abgewiesen (Rate-Limit)',
                'kontaktanfrage',
                "Ziel: {$zielBezeichner}",
                null,
                'GAST'
            );
            $this->zurueck($typ, $id, 'zuviele');
        }
        RateLimiter::recordAttempt($ip, 'kontaktanfrage-ip');
        RateLimiter::recordAttempt($zielBezeichner, 'kontaktanfrage-ziel');

        $name = Eingabe::einzeilig(is_string($_POST['name'] ?? null) ? $_POST['name'] : '', 150);
        $email = Eingabe::email(is_string($_POST['email'] ?? null) ? $_POST['email'] : '');
        $grundSchluessel = Eingabe::einzeilig(is_string($_POST['grund'] ?? null) ? $_POST['grund'] : '', 64);
        $gruende = Konfiguration::gruende();

        if ($name === '' || $email === null || !Gruende::istGueltig($grundSchluessel, $gruende)) {
            $this->zurueck($typ, $id, 'fehler');
        }

        $teamAdresse = Konfiguration::teamAdresse();
        $ziel = Ziel::oeffentlich($typ, $id);

        // Fehlender/unveröffentlichter Datensatz, Opt-out oder fehlende
        // Team-Adresse: verwerfen und "erfolg" melden. Der Rückgabestatus darf
        // kein Orakel dafür sein, welche IDs es gibt und wer Anfragen
        // abgeschaltet hat.
        if ($teamAdresse === null || $ziel === null || Ziel::abgeschaltet($typ, $id)) {
            $this->zurueck($typ, $id, 'erfolg');
        }

        $grundLabel = $gruende[$grundSchluessel];

        $db = Database::getInstance();
        $stmt = $db->prepare(
            'INSERT INTO `plugin_kontaktanfrage_requests`
                (target_type, target_id, reason_key, reason_label, requester_name, requester_email)
             VALUES (:typ, :id, :grund, :label, :name, :email)'
        );
        $stmt->execute([
            'typ' => $typ,
            'id' => $id,
            'grund' => $grundSchluessel,
            'label' => $grundLabel,
            'name' => $name,
            'email' => $email,
        ]);
        $anfrageId = (int) $db->lastInsertId();

        $seitenname = (string) ($this->settings['site_name'] ?? 'Hengstverzeichnis');
        $mailer = new Mailer();
        $versendet = $mailer->send(
            $teamAdresse,
            Nachricht::betreff("Kontaktanfrage ({$grundLabel}) - " . Ziel::bezeichnung($typ) . ' ' . $ziel['name']),
            Nachricht::anTeam($typ, $ziel, $grundLabel, $name, $email, $seitenname, $mailer->getBaseUrl())
        );

        if ($versendet) {
            $db->prepare('UPDATE `plugin_kontaktanfrage_requests` SET team_notified = 1 WHERE id = ?')
                ->execute([$anfrageId]);
        }

        AuditLogger::log(
            'Kontaktanfrage eingegangen',
            'kontaktanfrage',
            "Anfrage #{$anfrageId}, Ziel: {$zielBezeichner}, Grund: {$grundSchluessel}, "
                . ($versendet ? 'an Team zugestellt' : 'Zustellung an Team fehlgeschlagen'),
            null,
            'GAST'
        );

        // Fehlgeschlagener Versand ist kein Datenverlust - die Anfrage steht in
        // der Verwaltung und ist dort als unzugestellt erkennbar. Dem Besucher
        // wird das trotzdem als Fehler gemeldet: Er soll nicht auf eine
        // Antwort warten, die vielleicht niemand gesehen hat.
        $this->zurueck($typ, $id, $versendet ? 'erfolg' : 'fehler');
    }

    private function zurueck(string $typ, int $id, string $status): never {
        header('Location: ' . Ziel::oeffentlicherLink($typ, $id) . '&kontaktanfrage=' . $status);
        exit;
    }
}

/**
 * Backend: eingegangene Anfragen einsehen, weiterleiten, löschen und die
 * Addon-Einstellungen pflegen. Zugriff über die selbst registrierte
 * Berechtigung kontaktanfrage.manage.
 *
 * Die E-Mail-Adresse des EMPFÄNGERS wird hier bewusst nicht angezeigt -
 * gezeigt wird nur, ob eine hinterlegt ist. Wer Anfragen bearbeiten darf,
 * braucht dafür keinen Blick in die Kontaktdaten der Personen; das Recht
 * dazu ist persons.view und hängt nicht an diesem Addon.
 */
class VerwaltungController extends BaseController {

    private const JE_SEITE = 50;

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
        $this->requirePermission('kontaktanfrage', 'manage');
    }

    public function index(): void {
        $db = Database::getInstance();

        $gesamt = (int) $db->query('SELECT COUNT(*) FROM `plugin_kontaktanfrage_requests`')->fetchColumn();
        $seitenzahl = max(1, (int) ceil($gesamt / self::JE_SEITE));
        $seite = min($seitenzahl, self::seiteAusAnfrage());

        $stmt = $db->prepare(
            "SELECT r.*, p.name AS person_name, p.email AS person_email, p.deleted_at AS person_deleted,
                    s.name AS station_name, s.email AS station_email, s.deleted_at AS station_deleted
             FROM `plugin_kontaktanfrage_requests` r
             LEFT JOIN persons p ON r.target_type = 'person' AND p.id = r.target_id
             LEFT JOIN breeding_stations s ON r.target_type = 'station' AND s.id = r.target_id
             ORDER BY r.created_at DESC, r.id DESC
             LIMIT :limit OFFSET :offset"
        );
        $stmt->bindValue('limit', self::JE_SEITE, PDO::PARAM_INT);
        $stmt->bindValue('offset', ($seite - 1) * self::JE_SEITE, PDO::PARAM_INT);
        $stmt->execute();
        $anfragen = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $csrf = htmlspecialchars(Router::generateCsrfToken(), ENT_QUOTES, 'UTF-8');
        $inhalt = self::meldung();
        $inhalt .= $this->einstellungsKarte($csrf);
        $inhalt .= $this->anfragenKarte($anfragen, $csrf, $gesamt, $seite, $seitenzahl);

        PluginPage::render('Kontaktanfragen', $inhalt);
    }

    public function einstellungen(): void {
        $this->pruefeCsrf();

        $teamRoh = is_string($_POST['team_email'] ?? null) ? $_POST['team_email'] : '';
        $team = Eingabe::email($teamRoh);
        if ($team === null && trim($teamRoh) !== '') {
            $this->zurueck('adresse-ungueltig');
        }

        $zusatz = is_string($_POST['zusatz_gruende'] ?? null) ? $_POST['zusatz_gruende'] : '';
        // Gespeichert wird die aufbereitete Liste, nicht die Rohreingabe: Was
        // in den Einstellungen steht, ist danach exakt das, was das Formular
        // anbietet - keine stillen Unterschiede zwischen Anzeige und Prüfung.
        $zusatzListe = Gruende::ausText($zusatz);

        $tage = filter_var(
            $_POST['aufbewahrung_tage'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0, 'max_range' => Konfiguration::MAX_AUFBEWAHRUNG_TAGE]]
        );
        if (!is_int($tage)) {
            $this->zurueck('frist-ungueltig');
        }

        Konfiguration::setzen('team_email', $team ?? '');
        Konfiguration::setzen('zusatz_gruende', implode("\n", array_values($zusatzListe)));
        Konfiguration::setzen('aufbewahrung_tage', (string) $tage);

        AuditLogger::log(
            'Kontaktanfrage-Einstellungen geändert',
            'kontaktanfrage',
            'Team-Adresse ' . ($team === null ? 'geleert' : 'gesetzt')
                . ', Zusatzgründe: ' . count($zusatzListe)
                . ', Aufbewahrung: ' . ($tage === 0 ? 'unbegrenzt' : $tage . ' Tage')
        );

        $this->zurueck('gespeichert');
    }

    public function weiterleiten(): void {
        $this->pruefeCsrf();

        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($id)) {
            $this->zurueck('fehler');
        }

        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT * FROM `plugin_kontaktanfrage_requests` WHERE id = ?');
        $stmt->execute([$id]);
        $anfrage = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$anfrage) {
            $this->zurueck('fehler');
        }

        $typ = Ziel::ausAnfrage($anfrage['target_type']);
        $zielId = (int) $anfrage['target_id'];
        if ($typ === null) {
            $this->zurueck('fehler');
        }

        // Ein nach der Anfrage gesetztes Opt-out gilt auch rückwirkend: Der
        // Datensatz hat erklärt, keine Kontaktanfragen zu wollen, und eine
        // gespeicherte Anfrage ist kein Freibrief, das zu übergehen.
        if (Ziel::abgeschaltet($typ, $zielId)) {
            $this->zurueck('opt-out');
        }

        $ziel = Ziel::intern($typ, $zielId);
        if ($ziel === null) {
            $this->zurueck('kein-datensatz');
        }
        if ($ziel['email'] === null) {
            $this->zurueck('kein-empfaenger');
        }

        $seitenname = (string) ($this->settings['site_name'] ?? 'Hengstverzeichnis');
        $grundLabel = (string) $anfrage['reason_label'];
        $name = (string) $anfrage['requester_name'];
        $email = (string) $anfrage['requester_email'];

        $versendet = (new Mailer())->send(
            $ziel['email'],
            Nachricht::betreff("Kontaktanfrage über {$seitenname}: {$grundLabel}"),
            Nachricht::anEmpfaenger($typ, $ziel, $grundLabel, $name, $email, $seitenname)
        );

        if (!$versendet) {
            AuditLogger::log(
                'Weiterleitung einer Kontaktanfrage fehlgeschlagen',
                'kontaktanfrage',
                "Anfrage #{$id}, Ziel: {$typ}:{$zielId}"
            );
            $this->zurueck('versand-fehler');
        }

        $db->prepare(
            'UPDATE `plugin_kontaktanfrage_requests`
             SET forwarded_at = NOW(), forwarded_by = :benutzer
             WHERE id = :id'
        )->execute([
            'benutzer' => Eingabe::einzeilig((string) ($_SESSION['username'] ?? 'unbekannt'), 100),
            'id' => $id,
        ]);

        AuditLogger::log(
            'Kontaktanfrage weitergeleitet',
            'kontaktanfrage',
            "Anfrage #{$id}, Ziel: {$typ}:{$zielId}, Grund: {$anfrage['reason_key']}"
        );

        $this->zurueck('weitergeleitet');
    }

    public function loeschen(): void {
        $this->pruefeCsrf();

        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($id)) {
            $this->zurueck('fehler');
        }

        $stmt = Database::getInstance()->prepare('DELETE FROM `plugin_kontaktanfrage_requests` WHERE id = ?');
        $stmt->execute([$id]);

        if ($stmt->rowCount() > 0) {
            AuditLogger::log('Kontaktanfrage gelöscht', 'kontaktanfrage', "Anfrage #{$id}");
        }

        $this->zurueck('geloescht');
    }

    public function aufraeumen(): void {
        $this->pruefeCsrf();

        $anzahl = Aufraeumen::faellige();
        AuditLogger::log(
            'Kontaktanfragen von Hand aufgeräumt',
            'kontaktanfrage',
            "{$anzahl} Anfrage(n) gelöscht"
        );

        $this->zurueck('aufgeraeumt');
    }

    private function einstellungsKarte(string $csrf): string {
        $team = (string) (Konfiguration::alle()['team_email'] ?? '');
        $zusatz = Konfiguration::zusatzGruendeText();
        $tage = Konfiguration::aufbewahrungstage();

        $html = '<div class="card"><h1>✉️ Kontaktanfragen</h1>';
        $html .= '<p style="color:var(--text-muted);">Anfragen gehen immer an die Team-Adresse, nie direkt an die '
            . 'angefragte Person oder Deckstation. Von hier aus leitet das Team sie weiter, wenn Kontakt gewünscht ist.</p>';

        if (Konfiguration::teamAdresse() === null) {
            $html .= '<p style="color:var(--warning-fg);background:var(--warning-soft-bg);padding:0.6rem;'
                . 'border-radius:var(--border-radius, 4px);">Ohne gültige Team-Adresse erscheint auf den '
                . 'öffentlichen Seiten kein Formular.</p>';
        }

        $html .= '<form method="POST" action="/plugin/kontaktanfrage/verwaltung/einstellungen">';
        $html .= '<input type="hidden" name="csrf_token" value="' . $csrf . '">';

        $html .= '<div class="form-group"><label for="ka-team">Team-Adresse (Empfänger aller Anfragen)</label>'
            . '<input class="form-control" type="email" id="ka-team" name="team_email" maxlength="150" value="'
            . htmlspecialchars($team, ENT_QUOTES, 'UTF-8') . '"></div>';

        $html .= '<div class="form-group"><label for="ka-gruende">Zusätzliche Gründe (einer je Zeile)</label>'
            . '<textarea class="form-control" id="ka-gruende" name="zusatz_gruende" rows="5">'
            . htmlspecialchars($zusatz, ENT_QUOTES, 'UTF-8') . '</textarea>'
            . '<span class="form-hint">Fest eingebaut sind: '
            . htmlspecialchars(implode(', ', Gruende::STANDARD), ENT_QUOTES, 'UTF-8')
            . '. Höchstens ' . Gruende::MAX_ZUSATZ . ' weitere.</span></div>';

        $html .= '<div class="form-group"><label for="ka-frist">Aufbewahrung in Tagen (0 = keine automatische Löschung)</label>'
            . '<input class="form-control" type="number" id="ka-frist" name="aufbewahrung_tage" min="0" max="'
            . Konfiguration::MAX_AUFBEWAHRUNG_TAGE . '" value="' . $tage . '">'
            . '<span class="form-hint">Ältere Anfragen entfernt die Cron-Aufgabe kontaktanfrage.aufraeumen '
            . '(einmal täglich, siehe Admin -> Cron).</span></div>';

        $html .= '<button type="submit" class="btn">Einstellungen speichern</button>';
        $html .= '</form></div>';

        return $html;
    }

    /**
     * @param array<int, array<string, mixed>> $anfragen
     */
    private function anfragenKarte(array $anfragen, string $csrf, int $gesamt, int $seite, int $seitenzahl): string {
        $html = '<div class="card" style="margin-top:1.5rem;">';
        $html .= '<div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;">';
        $html .= '<h2 style="margin:0;">Eingegangene Anfragen (' . $gesamt . ')</h2>';
        $html .= '<form method="POST" action="/plugin/kontaktanfrage/verwaltung/aufraeumen">'
            . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
            . '<button type="submit" class="btn btn-secondary">Abgelaufene jetzt löschen</button></form>';
        $html .= '</div>';

        if ($anfragen === []) {
            $html .= '<p style="color:var(--text-muted);">Bisher sind keine Anfragen eingegangen.</p></div>';
            return $html;
        }

        $html .= '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;margin-top:1rem;">';
        $html .= '<thead><tr>'
            . '<th style="text-align:left;padding:0.5rem;border-bottom:1px solid var(--border-color);">Eingegangen</th>'
            . '<th style="text-align:left;padding:0.5rem;border-bottom:1px solid var(--border-color);">Ziel</th>'
            . '<th style="text-align:left;padding:0.5rem;border-bottom:1px solid var(--border-color);">Grund</th>'
            . '<th style="text-align:left;padding:0.5rem;border-bottom:1px solid var(--border-color);">Anfragender</th>'
            . '<th style="text-align:left;padding:0.5rem;border-bottom:1px solid var(--border-color);">Status</th>'
            . '<th style="text-align:left;padding:0.5rem;border-bottom:1px solid var(--border-color);">Aktion</th>'
            . '</tr></thead><tbody>';

        foreach ($anfragen as $anfrage) {
            $html .= $this->anfrageZeile($anfrage, $csrf);
        }

        $html .= '</tbody></table></div>';

        if ($seitenzahl > 1) {
            $html .= '<p style="margin-top:1rem;">';
            if ($seite > 1) {
                $html .= '<a class="btn btn-secondary" href="/plugin/kontaktanfrage/verwaltung?seite=' . ($seite - 1) . '">&laquo; Zurück</a> ';
            }
            $html .= 'Seite ' . $seite . ' von ' . $seitenzahl;
            if ($seite < $seitenzahl) {
                $html .= ' <a class="btn btn-secondary" href="/plugin/kontaktanfrage/verwaltung?seite=' . ($seite + 1) . '">Weiter &raquo;</a>';
            }
            $html .= '</p>';
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * @param array<string, mixed> $anfrage
     */
    private function anfrageZeile(array $anfrage, string $csrf): string {
        $e = static fn(mixed $wert): string => htmlspecialchars((string) $wert, ENT_QUOTES, 'UTF-8');

        $typ = Ziel::ausAnfrage($anfrage['target_type']);
        $zielId = (int) $anfrage['target_id'];
        $istPerson = $typ === Ziel::PERSON;
        $zielName = $istPerson ? ($anfrage['person_name'] ?? null) : ($anfrage['station_name'] ?? null);
        $zielEmail = $istPerson ? ($anfrage['person_email'] ?? null) : ($anfrage['station_email'] ?? null);
        $imPapierkorb = $istPerson ? ($anfrage['person_deleted'] ?? null) : ($anfrage['station_deleted'] ?? null);

        $zielZelle = $typ === null
            ? '<em>unbekannt</em>'
            : $e(Ziel::bezeichnung($typ)) . ': ';
        if ($typ !== null) {
            $zielZelle .= $zielName === null
                ? '<em>Datensatz entfernt</em>'
                : '<a href="' . $e(Ziel::bearbeitenLink($typ, $zielId)) . '">' . $e($zielName) . '</a>';
            if ($imPapierkorb !== null) {
                $zielZelle .= ' <span style="color:var(--warning-fg);">(Papierkorb)</span>';
            }
        }

        $status = [];
        $status[] = empty($anfrage['team_notified'])
            ? '<span style="color:var(--danger-fg);">nicht an Team zugestellt</span>'
            : 'an Team zugestellt';
        if (!empty($anfrage['forwarded_at'])) {
            $status[] = '<span style="color:var(--success-fg);">weitergeleitet am '
                . $e(self::zeitpunkt((string) $anfrage['forwarded_at'])) . '</span>';
        }

        $zelle = 'padding:0.5rem;border-bottom:1px solid var(--border-color);vertical-align:top;';

        $html = '<tr>';
        $html .= '<td style="' . $zelle . '">' . $e(self::zeitpunkt((string) $anfrage['created_at'])) . '</td>';
        $html .= '<td style="' . $zelle . '">' . $zielZelle . '</td>';
        $html .= '<td style="' . $zelle . '">' . $e($anfrage['reason_label']) . '</td>';
        $html .= '<td style="' . $zelle . '">' . $e($anfrage['requester_name']) . '<br>'
            . '<a href="mailto:' . $e($anfrage['requester_email']) . '">' . $e($anfrage['requester_email']) . '</a></td>';
        $html .= '<td style="' . $zelle . '">' . implode('<br>', $status) . '</td>';

        $html .= '<td style="' . $zelle . '">';
        if ($typ !== null && $zielEmail !== null && $zielName !== null) {
            $beschriftung = empty($anfrage['forwarded_at']) ? 'Weiterleiten' : 'Erneut weiterleiten';
            $html .= '<form method="POST" action="/plugin/kontaktanfrage/verwaltung/weiterleiten" style="display:inline;">'
                . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
                . '<input type="hidden" name="id" value="' . (int) $anfrage['id'] . '">'
                . '<button type="submit" class="btn">' . $beschriftung . '</button></form> ';
        } else {
            $html .= '<span style="color:var(--text-muted);font-size:0.85em;">keine Adresse hinterlegt</span><br>';
        }
        $html .= '<form method="POST" action="/plugin/kontaktanfrage/verwaltung/loeschen" style="display:inline;">'
            . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
            . '<input type="hidden" name="id" value="' . (int) $anfrage['id'] . '">'
            . '<button type="submit" class="btn btn-secondary">Löschen</button></form>';
        $html .= '</td></tr>';

        return $html;
    }

    private static function meldung(): string {
        $status = is_string($_GET['ka'] ?? null) ? $_GET['ka'] : '';

        $texte = [
            'gespeichert' => ['Einstellungen gespeichert.', 'success'],
            'weitergeleitet' => ['Anfrage weitergeleitet.', 'success'],
            'geloescht' => ['Anfrage gelöscht.', 'success'],
            'aufgeraeumt' => ['Abgelaufene Anfragen wurden entfernt.', 'success'],
            'adresse-ungueltig' => ['Die Team-Adresse ist keine gültige E-Mail-Adresse.', 'danger'],
            'frist-ungueltig' => ['Die Aufbewahrungsfrist muss eine Zahl von 0 bis ' . Konfiguration::MAX_AUFBEWAHRUNG_TAGE . ' sein.', 'danger'],
            'kein-empfaenger' => ['Für diesen Datensatz ist keine E-Mail-Adresse hinterlegt - die Anfrage lässt sich nicht weiterleiten.', 'danger'],
            'kein-datensatz' => ['Der angefragte Datensatz existiert nicht mehr oder liegt im Papierkorb - es wurde nichts weitergeleitet.', 'danger'],
            'opt-out' => ['Dieser Datensatz hat Kontaktanfragen abgeschaltet - es wurde nichts weitergeleitet.', 'danger'],
            'versand-fehler' => ['Die Weiterleitung konnte nicht versendet werden. Bitte den Mailversand prüfen.', 'danger'],
            'fehler' => ['Die Aktion konnte nicht ausgeführt werden.', 'danger'],
        ];

        if (!isset($texte[$status])) {
            return '';
        }

        [$text, $art] = $texte[$status];
        return '<div class="card" style="color:var(--' . $art . '-fg);background:var(--' . $art . '-soft-bg);">'
            . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</div>';
    }

    /**
     * Datumsangaben kommen aus der Datenbank und sind normalerweise lesbar -
     * ein unlesbarer Wert (strtotime liefert dann false) darf trotzdem nicht
     * als 01.01.1970 erscheinen, sondern als das, was er ist.
     */
    private static function zeitpunkt(string $wert): string {
        $stempel = strtotime($wert);
        return $stempel === false ? '—' : date('d.m.Y H:i', $stempel);
    }

    /**
     * Seitennummer aus der Anfrage - validiert, nicht umgedeutet: (int)"3x"
     * wäre 3, filter_var lehnt ab, was keine Zahl IST.
     */
    private static function seiteAusAnfrage(): int {
        $wert = filter_var($_GET['seite'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]);
        return is_int($wert) ? $wert : 1;
    }

    private function pruefeCsrf(): void {
        if (!Router::verifyCsrfToken(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }
    }

    private function zurueck(string $status): never {
        header('Location: /plugin/kontaktanfrage/verwaltung?ka=' . $status);
        exit;
    }
}

/**
 * Schreibt das Opt-out eines einzelnen Datensatzes. Eigener Controller, weil
 * hier eine ANDERE Berechtigung gilt als in der Verwaltung: Wer eine Person
 * bearbeiten darf, darf über deren Erreichbarkeit entscheiden - dafür braucht
 * er nicht das Recht, alle eingegangenen Anfragen zu lesen.
 */
class OptOutController extends BaseController {

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
    }

    public function speichern(): void {
        if (!Router::verifyCsrfToken(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }

        $typ = Ziel::ausAnfrage($_POST['ziel_typ'] ?? null);
        $id = filter_var($_POST['ziel_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($typ === null || !is_int($id)) {
            header('Location: /admin');
            exit;
        }

        // Die Berechtigung hängt am Zieltyp und wird deshalb erst hier
        // geprüft, nicht im Konstruktor.
        $this->requirePermission(Ziel::modul($typ), 'edit');

        $erlaubt = ($_POST['erlaubt'] ?? '0') === '1';
        $db = Database::getInstance();

        if ($erlaubt) {
            $db->prepare('DELETE FROM `plugin_kontaktanfrage_optout` WHERE target_type = ? AND target_id = ?')
                ->execute([$typ, $id]);
        } else {
            $db->prepare(
                'INSERT INTO `plugin_kontaktanfrage_optout` (target_type, target_id, disabled_by)
                 VALUES (:typ, :id, :benutzer)
                 ON DUPLICATE KEY UPDATE disabled_by = :benutzer2'
            )->execute([
                'typ' => $typ,
                'id' => $id,
                'benutzer' => Eingabe::einzeilig((string) ($_SESSION['username'] ?? 'unbekannt'), 100),
                'benutzer2' => Eingabe::einzeilig((string) ($_SESSION['username'] ?? 'unbekannt'), 100),
            ]);
        }

        AuditLogger::log(
            $erlaubt ? 'Kontaktanfragen zugelassen' : 'Kontaktanfragen abgeschaltet',
            'kontaktanfrage',
            "Ziel: {$typ}:{$id}"
        );

        header('Location: ' . Ziel::bearbeitenLink($typ, $id));
        exit;
    }
}
