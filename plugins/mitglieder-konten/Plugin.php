<?php
// mitglieder-konten/Plugin.php
//
// Addon für Hengstverzeichnis_Framework, löst Addons#131.
//
// WAS ES TUT: Es legt Benutzerkonten für Verbandsmitglieder an. Nichts
// weiter. Benutzername = Mitgliedschafts-ID aus CiviCRM, Erstpasswort
// erzeugt, Zuordnung zu einer reinen Lesegruppe. Hat ein Mitglied keine
// eigene Adresse, gehen die Zugangsdaten gesammelt an das Verwaltungsteam.
//
// DIE ENTSCHEIDUNGEN, DIE MAN DEM CODE SONST NICHT ANSIEHT:
//
//  1. KEIN DATENABGLEICH. Weder Mitgliedsstatus noch Anschrift noch
//     Kontaktdaten, und nichts zurück nach CiviCRM. CiviCRM beantwortet genau
//     zwei Fragen: wer bekommt ein Konto, und unter welcher Nummer. Der
//     Client kann deshalb auch nur lesen (siehe CiviApi.php).
//
//  2. AUSWAHLLAUF STATT AUTOMATIK. Auf der Erprobungsinstanz stehen 1.496
//     Mitglieder. Ein Lauf, der ungefragt 1.496 Konten anlegt und 1.496 Mails
//     verschickt, ist nicht rückholbar. Also: Vorschau, Auswahl, Anlage in
//     Stapeln. Die Vorschau zeigt VOR dem ersten Konto, was schiefgehen
//     würde - belegte Namen, Nummern mit `@`, fehlende Lesegruppe.
//
//  3. BENUTZERNAME = MITGLIEDSCHAFTS-ID (vom Betreiber so entschieden).
//     Die Folge gehört benannt: Endet eine Mitgliedschaft und tritt jemand
//     später neu ein, vergibt CiviCRM eine NEUE Mitgliedschafts-ID. Es
//     entsteht dann ein zweites Konto, und das erste bleibt gesperrt stehen.
//     Das ist mit Punkt 4 stimmig - ein Konto je Mitgliedschaftszeitraum -,
//     aber es ist eine Entscheidung und kein Zufall.
//
//  4. ENDET DIE MITGLIEDSCHAFT, WIRD GESPERRT - NIE GELÖSCHT. Der tägliche
//     Lauf liest den Mitgliedszustand (mehr nicht, er speichert ihn nicht)
//     und setzt für Konten ohne laufende Mitgliedschaft `deactivated_at`.
//     Eine Sperre ist umkehrbar, eine Löschung nimmt Zuordnungen und Spuren
//     mit (Framework#358).
//
//  5. DAS KONTO LEGT DER KERN AN, nicht dieses Addon:
//     App\Service\UserProvisioning (Framework#384). Ein nachgebauter
//     Anlegevorgang verfehlt irgendwann eine Vorgabe - must_change_password,
//     die Adresspflicht nach Rechten, das @-Verbot im Benutzernamen, die
//     Filterung der Gast-Gruppe. Genau dafür gibt es den Dienst.
//
//  6. KLARTEXT-PASSWORT STATT EINMAL-LINK, MIT BEGRÜNDUNG. Ein Einmal-Link
//     wäre besser - er stünde nie in einem Postfach. Der Rückweg des Kerns
//     ist aber auf die E-Mail-ADRESSE geschlüsselt (`password_resets.email`),
//     und die Konten, um die es hier geht, haben definitionsgemäss keine.
//     Deshalb: erzeugtes Passwort, `must_change_password = 1` (der Kern setzt
//     das bei jeder Neuanlage), und der Hinweis in der Mail, es sofort zu
//     wechseln.
//
// Installation (lokal im Framework-Repo):
//   cp -r mitglieder-konten plugins/mitglieder-konten
// Danach unter Admin -> Plugins verwalten (/admin/plugins) aktivieren.

namespace Plugin\MitgliederKonten;

use App\Controllers\BaseController;
use App\Database;
use App\Permission\EmailRequirement;
use App\Plugin\HookManager;
use App\Plugin\PluginAudit;
use App\Plugin\PluginPage;
use App\Router;
use App\Security\Crypto;
use App\Security\LoginIdentifier;
use App\Service\AuditLogger;
use App\Service\Mailer;
use App\Service\Scheduler;
use App\Service\UserProvisioning;
use PDO;

require_once __DIR__ . '/CiviApi.php';

class Plugin {

    public const SLUG = 'mitglieder-konten';
    public const MODUL = 'mitglieder_konten';
    public const VERWALTUNG = '/plugin/mitglieder-konten/verwaltung';

    /** Name der taeglichen Aufgabe im Scheduler des Kerns. */
    public const AUFGABE = 'mitglieder-konten.abgleich';

    public function register(HookManager $hooks): void {
        $hooks->addFilter('admin.dashboard_tiles', [$this, 'dashboardKachel']);

        // Bewusst ohne Datenbankzugriff - das laeuft im Bootstrap JEDES
        // Requests. Was der Lauf tut, entscheidet sich erst beim Ausfuehren.
        Scheduler::register(self::AUFGABE, 86400, [Abgleich::class, 'taeglicherLauf']);
    }

    public function install(): void {
        $db = Database::getInstance();

        // Der Primaerschluessel ist die Mitgliedschafts-ID und nicht die
        // Benutzer-ID: Er ist der Schutz gegen die stille Zweitanlage. Laeuft
        // der Abgleich erneut, findet er die Zeile und legt kein zweites
        // Konto an.
        //
        // ON DELETE CASCADE am Benutzer: Wird das Konto endgueltig geloescht
        // (DSGVO), darf die Zuordnung nicht als Rest liegenbleiben - sie
        // verknuepft eine Mitgliedsnummer mit einer Person.
        $db->exec(
            'CREATE TABLE IF NOT EXISTS `' . Zuordnung::TABELLE . '` (
                `membership_id` INT UNSIGNED NOT NULL PRIMARY KEY,
                `user_id` INT NOT NULL,
                `civicrm_contact_id` INT UNSIGNED NOT NULL,
                `angelegt_am` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `gesperrt_am` DATETIME NULL DEFAULT NULL,
                UNIQUE KEY `uq_mk_user` (`user_id`),
                INDEX `idx_mk_kontakt` (`civicrm_contact_id`),
                CONSTRAINT `fk_mk_user` FOREIGN KEY (`user_id`)
                    REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /**
     * Framework#338: Tabelle und Einstellungen stehen unter `owns` im
     * Manifest und werden von dort entfernt.
     *
     * Die ANGELEGTEN KONTEN bleiben - ausdruecklich. Sie gehoeren dem
     * Betreiber, nicht diesem Addon; Menschen melden sich damit an. Ein
     * Deinstallieren, das Benutzerkonten mitnimmt, waere ein Datenverlust,
     * den niemand erwartet. Ebenso bleiben die Protokolleintraege: Ein
     * Nachweis, den das Deinstallieren mitnimmt, ist keiner.
     */
    public function uninstall(): void {
        PluginAudit::log(
            self::SLUG,
            'Addon deinstalliert',
            'Zuordnungstabelle und Einstellungen entfernt. Die angelegten Benutzerkonten bleiben bestehen - '
            . 'sie gehoeren dem Betreiber. Ohne die Zuordnung endet allerdings die automatische Sperre bei '
            . 'beendeter Mitgliedschaft.'
        );
    }

    /** @return array<int, array<string, string>> */
    public function permissions(): array {
        return [
            [
                'module' => self::MODUL,
                'action' => 'manage',
                'label' => 'Mitglieder-Konten anlegen und den CiviCRM-Zugang pflegen',
                'module_label' => 'Mitglieder-Konten',
            ],
        ];
    }

    /**
     * @param array<int, array<string, string>> $tiles
     * @return array<int, array<string, string>>
     */
    public function dashboardKachel(array $tiles): array {
        if (!GruppenHelfer::darfVerwalten()) {
            return $tiles;
        }

        $tiles[] = [
            'url' => self::VERWALTUNG,
            'label' => 'Mitglieder-Konten',
            'icon' => '👥',
        ];

        return $tiles;
    }

    /** @return array<int, array{method:string, path:string, callback:array}> */
    public function routes(): array {
        return [
            ['method' => 'GET',  'path' => '/verwaltung',            'callback' => [VerwaltungController::class, 'index']],
            ['method' => 'POST', 'path' => '/verwaltung/zugang',     'callback' => [VerwaltungController::class, 'zugang']],
            ['method' => 'POST', 'path' => '/verwaltung/anlegen',    'callback' => [VerwaltungController::class, 'anlegen']],
        ];
    }
}

/**
 * Rechtefrage an einer Stelle - `manage` ist das einzige Recht dieses Addons,
 * und `admin` hat es systemseitig ohnehin.
 */
final class GruppenHelfer {

    private function __construct() {}

    public static function darfVerwalten(): bool {
        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

        return \App\Permission\GroupMembership::hasPermission($userId, Plugin::MODUL, 'manage');
    }
}

/**
 * Die Einstellungen. Fuenf Werte, deshalb kein eigenes Schema - das Register
 * `owns` zaehlt sie auf und entfernt sie beim Deinstallieren.
 */
final class Konfiguration {

    public const S_URL = 'plugin_mitglieder_konten_url';
    public const S_KEY = 'plugin_mitglieder_konten_key';
    public const S_GRUPPE = 'plugin_mitglieder_konten_gruppe';
    public const S_TEAM = 'plugin_mitglieder_konten_team_email';
    public const S_TYPEN = 'plugin_mitglieder_konten_typen';

    /** @var array<string, string>|null */
    private static ?array $cache = null;

    private function __construct() {}

    /** @return array<string, string> */
    private static function alle(): array {
        if (self::$cache !== null) {
            return self::$cache;
        }

        try {
            $stmt = Database::getInstance()->prepare(
                'SELECT setting_key, setting_value FROM settings WHERE setting_key IN (?, ?, ?, ?, ?)'
            );
            $stmt->execute([self::S_URL, self::S_KEY, self::S_GRUPPE, self::S_TEAM, self::S_TYPEN]);
            $zeilen = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            self::$cache = is_array($zeilen) ? $zeilen : [];
        } catch (\Throwable $e) {
            // Ohne Einstellungen gilt "nicht eingerichtet" - der strengere Fall.
            self::$cache = [];
        }

        return self::$cache;
    }

    public static function leereCache(): void {
        self::$cache = null;
    }

    public static function basis(): string {
        return trim((string)(self::alle()[self::S_URL] ?? ''));
    }

    /**
     * Der API-Schluessel liegt verschluesselt in `settings` (AES-256-GCM ueber
     * App\Security\Crypto, derselbe Weg wie das TOTP-Secret im Kern). Im
     * Klartext waere er in jedem Datenbank-Dump und in jeder Sicherung
     * mitgelaufen - fuer einen Schluessel, mit dem man den kompletten
     * Mitgliederbestand lesen kann.
     */
    public static function apiKey(): string {
        $roh = (string)(self::alle()[self::S_KEY] ?? '');
        if ($roh === '') {
            return '';
        }

        return (string)(Crypto::decrypt($roh) ?? '');
    }

    public static function gruppeId(): int {
        return (int)(self::alle()[self::S_GRUPPE] ?? 0);
    }

    public static function teamAdresse(): string {
        return trim((string)(self::alle()[self::S_TEAM] ?? ''));
    }

    /** @return array<int, int> Leere Liste = alle Mitgliedschaftsarten */
    public static function typIds(): array {
        $roh = trim((string)(self::alle()[self::S_TYPEN] ?? ''));
        if ($roh === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('intval', preg_split('/[\s,]+/', $roh) ?: []),
            static fn(int $id): bool => $id > 0
        ));
    }

    /** @param array<string, ?string> $werte */
    public static function speichern(array $werte): void {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        foreach ($werte as $schluessel => $wert) {
            if ($wert === null) {
                continue; // null heisst "unveraendert lassen"
            }
            $stmt->execute([$schluessel, $wert]);
        }
        self::leereCache();
    }

    public static function client(): CiviApi {
        return new CiviApi(self::basis(), self::apiKey());
    }

    /**
     * Prueft und normalisiert die Basis-URL.
     *
     * Nur http/https, kein Pfad, keine Query: Die Adresse wird zu einem
     * API-Endpunkt zusammengesetzt, und eine Basis mit eigener Query fuehrte
     * woanders hin als gedacht. `javascript:` und `data:` scheiden damit
     * ebenfalls aus.
     */
    public static function pruefeBasis(string $eingabe): ?string {
        $eingabe = trim($eingabe);
        if ($eingabe === '') {
            return '';
        }

        $teile = parse_url($eingabe);
        if (!is_array($teile) || !in_array($teile['scheme'] ?? '', ['http', 'https'], true)) {
            return null;
        }
        if (($teile['host'] ?? '') === '' || ($teile['query'] ?? '') !== '' || ($teile['fragment'] ?? '') !== '') {
            return null;
        }

        return rtrim($eingabe, '/');
    }
}

/**
 * Die Zuordnung Mitgliedschafts-ID -> Benutzerkonto.
 *
 * Sie ist der Schutz gegen die stille Zweitanlage (Addons#131): Laeuft der
 * Abgleich erneut, findet er hier, was es schon gibt, und legt kein zweites
 * Konto an und setzt kein Passwort zurueck.
 */
final class Zuordnung {

    public const TABELLE = 'plugin_mitglieder_konten_zuordnung';

    private function __construct() {}

    /** @return array<int, int> membership_id => user_id */
    public static function alle(): array {
        try {
            $zeilen = Database::getInstance()
                ->query('SELECT membership_id, user_id FROM `' . self::TABELLE . '`')
                ->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (\Throwable $e) {
            return [];
        }

        $ergebnis = [];
        foreach ((array)$zeilen as $mitgliedschaft => $benutzer) {
            $ergebnis[(int)$mitgliedschaft] = (int)$benutzer;
        }

        return $ergebnis;
    }

    public static function merken(int $membershipId, int $userId, int $civicrmContactId): void {
        $stmt = Database::getInstance()->prepare(
            'INSERT INTO `' . self::TABELLE . '` (membership_id, user_id, civicrm_contact_id)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), civicrm_contact_id = VALUES(civicrm_contact_id)'
        );
        $stmt->execute([$membershipId, $userId, $civicrmContactId]);
    }

    public static function sperrvermerk(int $membershipId): void {
        $stmt = Database::getInstance()->prepare(
            'UPDATE `' . self::TABELLE . '` SET gesperrt_am = NOW() WHERE membership_id = ? AND gesperrt_am IS NULL'
        );
        $stmt->execute([$membershipId]);
    }
}

/**
 * Der fachliche Kern: Vorschau, Anlage, taeglicher Lauf.
 *
 * Ohne HTTP und ohne Ausgabe - was hier steht, ist gegen eine Attrappe des
 * CiviCRM-Zugangs pruefbar (siehe CiviApi::sende()).
 */
final class Abgleich {

    /** Hoechstzahl je Anlagestapel. Eine Bremse, keine Leistungsgrenze. */
    public const MAX_JE_STAPEL = 100;

    private function __construct() {}

    /**
     * Was ein Anlagelauf taete - VOR dem ersten Konto.
     *
     * Jede Zeile traegt ihren Zustand: `neu` (wird angelegt), `vorhanden`
     * (hat schon ein Konto), oder ein Hinderungsgrund. Die Hinderungsgruende
     * sind genau die, die der Kern beim Anlegen zurueckweisen wuerde - sie
     * hier zu zeigen ist der Unterschied zwischen "1.496 Konten, 37 Fehler
     * im Protokoll" und "37 Faelle, die vorher zu klaeren sind".
     *
     * @return array{zeilen: array<int, array<string, mixed>>, fehler: ?string}
     */
    public static function vorschau(?CiviApi $client = null): array {
        $client ??= Konfiguration::client();

        if (!$client->eingerichtet()) {
            return ['zeilen' => [], 'fehler' => 'Der CiviCRM-Zugang ist noch nicht eingerichtet.'];
        }

        try {
            $mitgliedschaften = $client->laufendeMitgliedschaften(Konfiguration::typIds());
        } catch (CiviApiFehler $e) {
            return ['zeilen' => [], 'fehler' => $e->getMessage()];
        }

        $bekannt = Zuordnung::alle();
        $belegt = self::belegteBenutzernamen();
        $db = Database::getInstance();
        $gruppePflichtAdresse = EmailRequirement::groupsRequireEmail($db, [Konfiguration::gruppeId()]);

        $zeilen = [];
        foreach ($mitgliedschaften as $m) {
            $benutzername = (string)$m['membership_id'];
            $zeile = [
                'membership_id' => $m['membership_id'],
                'contact_id' => $m['contact_id'],
                'name' => $m['name'],
                'email' => $m['email'],
                'benutzername' => $benutzername,
                'zustand' => 'neu',
                'grund' => '',
            ];

            if (isset($bekannt[$m['membership_id']])) {
                $zeile['zustand'] = 'vorhanden';
            } elseif (isset($belegt[mb_strtolower($benutzername, 'UTF-8')])) {
                $zeile['zustand'] = 'blockiert';
                $zeile['grund'] = 'Der Benutzername ist bereits vergeben - nicht durch dieses Addon.';
            } elseif (LoginIdentifier::usernameErrors($benutzername) !== []) {
                $zeile['zustand'] = 'blockiert';
                $zeile['grund'] = implode(' ', LoginIdentifier::usernameErrors($benutzername));
            } elseif ($m['email'] === '' && $gruppePflichtAdresse) {
                // Die harte Kopplung aus Addons#131: Nach Framework#348 duerfen
                // Konten ohne Adresse nur Leserechte haben. Ist die Zielgruppe
                // keine reine Lesegruppe, sind Mitglieder ohne Adresse gar
                // nicht anlegbar - das gehoert in die Vorschau, nicht in einen
                // Fehler nach dem 300. Konto.
                $zeile['zustand'] = 'blockiert';
                $zeile['grund'] = 'Ohne eigene Adresse, und die Zielgruppe gibt mehr als Leserechte. '
                                . 'Bitte eine reine Lesegruppe waehlen.';
            }

            $zeilen[] = $zeile;
        }

        usort($zeilen, static fn(array $a, array $b): int => $a['membership_id'] <=> $b['membership_id']);

        return ['zeilen' => $zeilen, 'fehler' => null];
    }

    /**
     * Legt die ausgewaehlten Konten an.
     *
     * @param array<int, int> $membershipIds
     * @return array{angelegt: int, uebersprungen: int, fehler: array<int, string>, zugangsdaten: array<int, array<string,string>>}
     */
    public static function anlegen(array $membershipIds, ?CiviApi $client = null): array {
        $client ??= Konfiguration::client();
        $ergebnis = ['angelegt' => 0, 'uebersprungen' => 0, 'fehler' => [], 'zugangsdaten' => []];

        $auswahl = array_slice(array_values(array_unique(array_map('intval', $membershipIds))), 0, self::MAX_JE_STAPEL);
        if ($auswahl === []) {
            return $ergebnis;
        }

        $vorschau = self::vorschau($client);
        if ($vorschau['fehler'] !== null) {
            $ergebnis['fehler'][] = $vorschau['fehler'];
            return $ergebnis;
        }

        $nachId = [];
        foreach ($vorschau['zeilen'] as $zeile) {
            $nachId[(int)$zeile['membership_id']] = $zeile;
        }

        $db = Database::getInstance();
        $gruppe = Konfiguration::gruppeId();

        foreach ($auswahl as $membershipId) {
            $zeile = $nachId[$membershipId] ?? null;

            // Die Auswahl kommt aus einem Formular und ist damit
            // nutzergesteuert: Was in der Vorschau nicht als `neu` steht, wird
            // nicht angelegt - auch wenn es im POST steht.
            if ($zeile === null || $zeile['zustand'] !== 'neu') {
                $ergebnis['uebersprungen']++;
                continue;
            }

            $passwort = UserProvisioning::erzeugePasswort();
            $angelegt = UserProvisioning::create(
                $db,
                (string)$zeile['benutzername'],
                (string)$zeile['email'],
                $passwort,
                $gruppe > 0 ? [$gruppe] : [],
                'CiviCRM-Mitgliederabgleich'
            );

            if (!$angelegt->erfolgreich()) {
                $ergebnis['fehler'][] = sprintf('Mitgliedschaft %d: %s', $membershipId, implode(' ', $angelegt->errors));
                continue;
            }

            Zuordnung::merken($membershipId, $angelegt->userId, (int)$zeile['contact_id']);
            $ergebnis['angelegt']++;
            $ergebnis['zugangsdaten'][] = [
                'benutzername' => (string)$zeile['benutzername'],
                'passwort' => $passwort,
                'email' => (string)$zeile['email'],
                'name' => (string)$zeile['name'],
            ];
        }

        self::zugangsdatenZustellen($ergebnis['zugangsdaten']);

        return $ergebnis;
    }

    /**
     * Zustellung: an das Mitglied, wenn es eine Adresse hat - sonst gesammelt
     * an das Verwaltungsteam.
     *
     * GESAMMELT und nicht je Konto: Bei einem Stapel ohne Adressen waeren das
     * sonst hundert Mails an dieselbe Stelle, und die hundertste geht unter.
     *
     * @param array<int, array<string, string>> $daten
     */
    private static function zugangsdatenZustellen(array $daten): void {
        if ($daten === []) {
            return;
        }

        $mailer = new Mailer();
        $ohneAdresse = [];

        foreach ($daten as $satz) {
            if ($satz['email'] === '') {
                $ohneAdresse[] = $satz;
                continue;
            }
            $mailer->sendWelcomeEmail($satz['email'], $satz['benutzername'], $satz['passwort']);
        }

        if ($ohneAdresse === []) {
            return;
        }

        $team = Konfiguration::teamAdresse();
        if ($team === '') {
            AuditLogger::log(
                'Zugangsdaten nicht zustellbar',
                'users',
                sprintf(
                    '%d Konto/Konten ohne eigene Adresse angelegt, aber keine Adresse des Verwaltungsteams '
                    . 'hinterlegt. Die Passwoerter sind damit nirgends - die Konten brauchen ein neu '
                    . 'gesetztes Passwort durch einen Admin.',
                    count($ohneAdresse)
                )
            );
            return;
        }

        $mailer->send($team, 'Neue Mitglieder-Konten - Zugangsdaten', self::teamMailHtml($ohneAdresse));
    }

    /**
     * Das HTML der Sammelmail an das Verwaltungsteam.
     *
     * theming-ausnahme: Das hier ist eine E-Mail, keine Seite. Ein Postfach
     * kennt weder die CSS-Variablen des Kerns noch seinen Theme-Umschalter -
     * Schrift und Farbe muessen ausgeschrieben dastehen, genau wie in
     * App\Service\Mailer im Kern. Der Marker gilt vier Zeilen weit, deshalb
     * ist das hier eine eigene, kurze Methode.
     *
     * @param array<int, array<string, string>> $ohneAdresse
     */
    private static function teamMailHtml(array $ohneAdresse): string {
        /* theming-ausnahme: siehe Methodenkopf - E-Mail statt Seite */
        $stilZelle = "padding:4px 12px 4px 0";
        $stilCode = "padding:4px 0;font-family:monospace";
        $stilRahmen = "font-family: Arial, sans-serif; max-width: 700px;";
        /* theming-ausnahme: siehe Methodenkopf - E-Mail statt Seite */
        $stilWarnung = "color:#a00";

        $zeilen = '';
        foreach ($ohneAdresse as $satz) {
            $zeilen .= sprintf(
                "<tr><td style='%s'>%s</td><td style='%s'>%s</td><td style='%s'>%s</td></tr>",
                $stilZelle,
                htmlspecialchars($satz['name'], ENT_QUOTES, 'UTF-8'),
                $stilZelle,
                htmlspecialchars($satz['benutzername'], ENT_QUOTES, 'UTF-8'),
                $stilCode,
                htmlspecialchars($satz['passwort'], ENT_QUOTES, 'UTF-8')
            );
        }

        return "<div style='{$stilRahmen}'>"
             . '<h2>Neue Mitglieder-Konten</h2>'
             . '<p>Diese Mitglieder haben keine eigene E-Mail-Adresse. Bitte stellen Sie die Zugangsdaten '
             . 'auf anderem Weg zu.</p>'
             . "<table><tr><th align='left'>Mitglied</th><th align='left'>Benutzername</th>"
             . "<th align='left'>Erstpasswort</th></tr>{$zeilen}</table>"
             . "<p style='{$stilWarnung}'><strong>Jedes dieser Passwoerter gilt genau bis zur ersten "
             . 'Anmeldung</strong> - danach verlangt das Verzeichnis ein neues. Loeschen Sie diese '
             . 'Nachricht, sobald die Daten heraus sind.</p></div>';
    }

    /**
     * Der taegliche Lauf. Er legt NICHTS an - Anlegen ist eine bewusste
     * Handlung mit Vorschau (Addons#131). Er sperrt nur, was nicht mehr
     * laeuft.
     *
     * @return array{geprueft: int, gesperrt: int}
     */
    public static function taeglicherLauf(?CiviApi $client = null): array {
        $client ??= Konfiguration::client();
        if (!$client->eingerichtet()) {
            return ['geprueft' => 0, 'gesperrt' => 0];
        }

        try {
            $laufend = $client->laufendeMitgliedschaften(Konfiguration::typIds());
        } catch (CiviApiFehler $e) {
            // Ein Umgebungsfehler ist kein Ergebnis: Waere CiviCRM
            // unerreichbar und wir deuteten das als "keine Mitgliedschaft
            // laeuft mehr", spaerrte der Lauf ueber Nacht JEDES Konto.
            AuditLogger::log(
                'Mitglieder-Abgleich nicht durchgefuehrt',
                'users',
                'CiviCRM war nicht erreichbar: ' . $e->getMessage() . ' - es wurde nichts gesperrt.'
            );
            return ['geprueft' => 0, 'gesperrt' => 0];
        }

        $laufendeIds = [];
        foreach ($laufend as $m) {
            $laufendeIds[(int)$m['membership_id']] = true;
        }

        $db = Database::getInstance();
        $gesperrt = 0;
        $zuordnungen = Zuordnung::alle();

        foreach ($zuordnungen as $membershipId => $userId) {
            if (isset($laufendeIds[$membershipId])) {
                continue;
            }

            $stmt = $db->prepare(
                "UPDATE users SET deactivated_at = NOW(), deactivated_reason = 'membership_ended'
                 WHERE id = ? AND deleted_at IS NULL AND deactivated_at IS NULL"
            );
            $stmt->execute([$userId]);

            if ($stmt->rowCount() > 0) {
                Zuordnung::sperrvermerk($membershipId);
                $gesperrt++;
                AuditLogger::log(
                    'Konto gesperrt (Mitgliedschaft beendet)',
                    'users',
                    sprintf('Benutzer-ID %d, Mitgliedschaft %d laeuft nicht mehr', $userId, $membershipId)
                );
            }
        }

        return ['geprueft' => count($zuordnungen), 'gesperrt' => $gesperrt];
    }

    /** @return array<string, true> Kleingeschriebene Benutzernamen */
    private static function belegteBenutzernamen(): array {
        try {
            $namen = Database::getInstance()
                ->query('SELECT username FROM users')
                ->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Throwable $e) {
            return [];
        }

        $belegt = [];
        foreach ((array)$namen as $name) {
            $belegt[mb_strtolower((string)$name, 'UTF-8')] = true;
        }

        return $belegt;
    }
}

/**
 * Die Verwaltungsseite: Zugang einrichten, Vorschau ansehen, Konten anlegen.
 *
 * Es gibt bewusst KEINEN Knopf "alle anlegen". Die Vorschau zeigt jede Zeile
 * mit ihrem Zustand, ausgewaehlt wird ausdruecklich, und ein Stapel ist auf
 * Abgleich::MAX_JE_STAPEL gedeckelt. 1.496 Konten auf einen Klick waeren
 * nicht rueckholbar (Addons#131).
 */
class VerwaltungController extends BaseController {

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
        $this->requirePermission(Plugin::MODUL, 'manage');
    }

    public function index(): void {
        $inhalt = $this->meldung();
        $inhalt .= $this->zugangKarte();
        $inhalt .= $this->vorschauKarte();

        PluginPage::render('Mitglieder-Konten', $inhalt);
    }

    public function zugang(): void {
        $this->pruefeCsrf();

        $basis = Konfiguration::pruefeBasis(is_string($_POST['basis_url'] ?? null) ? $_POST['basis_url'] : '');
        if ($basis === null) {
            $this->zurueck('url-ungueltig');
        }

        $team = trim(is_string($_POST['team_email'] ?? null) ? $_POST['team_email'] : '');
        if ($team !== '' && !filter_var($team, FILTER_VALIDATE_EMAIL)) {
            $this->zurueck('team-ungueltig');
        }

        // Ein leeres Schluesselfeld heisst "nicht aendern", nicht "loeschen":
        // Sonst wuerde jedes Speichern der uebrigen Einstellungen den
        // Schluessel mit entfernen, weil das Formular ihn nie zurueckgibt.
        $schluesselRoh = is_string($_POST['api_key'] ?? null) ? trim($_POST['api_key']) : '';
        $schluessel = $schluesselRoh === '' ? null : Crypto::encrypt($schluesselRoh);

        Konfiguration::speichern([
            Konfiguration::S_URL => $basis,
            Konfiguration::S_KEY => $schluessel,
            Konfiguration::S_TEAM => $team,
            Konfiguration::S_GRUPPE => (string)(int)($_POST['gruppe'] ?? 0),
            Konfiguration::S_TYPEN => trim(is_string($_POST['typen'] ?? null) ? $_POST['typen'] : ''),
        ]);

        // Der Schluessel selbst steht NICHT im Protokoll - nur, dass er
        // gesetzt wurde.
        PluginAudit::log(
            Plugin::SLUG,
            'CiviCRM-Zugang gespeichert',
            'Mitglieder-Konten',
            sprintf('Basis: %s, Schluessel %s', $basis === '' ? '(leer)' : $basis, $schluessel === null ? 'unveraendert' : 'neu gesetzt')
        );

        $this->zurueck('gespeichert');
    }

    public function anlegen(): void {
        $this->pruefeCsrf();

        $auswahl = array_map('intval', (array)($_POST['membership_ids'] ?? []));
        if ($auswahl === []) {
            $this->zurueck('nichts-ausgewaehlt');
        }

        $ergebnis = Abgleich::anlegen($auswahl);

        PluginAudit::log(
            Plugin::SLUG,
            'Mitglieder-Konten angelegt',
            'Mitglieder-Konten',
            sprintf(
                '%d angelegt, %d uebersprungen, %d Fehler',
                $ergebnis['angelegt'],
                $ergebnis['uebersprungen'],
                count($ergebnis['fehler'])
            )
        );

        $_SESSION['mitglieder_konten_bericht'] = [
            'angelegt' => $ergebnis['angelegt'],
            'uebersprungen' => $ergebnis['uebersprungen'],
            'fehler' => $ergebnis['fehler'],
        ];

        $this->zurueck('angelegt');
    }

    // ---- Anzeige -------------------------------------------------------

    private function meldung(): string {
        $marker = is_string($_GET['mk'] ?? null) ? $_GET['mk'] : '';
        $texte = [
            'gespeichert' => ['ok', 'Zugang gespeichert.'],
            'url-ungueltig' => ['fehler', 'Die Adresse muss mit http:// oder https:// beginnen und darf keinen Pfad und keine Parameter enthalten.'],
            'team-ungueltig' => ['fehler', 'Die Adresse des Verwaltungsteams ist keine gültige E-Mail-Adresse.'],
            'nichts-ausgewaehlt' => ['fehler', 'Es war nichts ausgewählt.'],
        ];

        $html = '';

        if (isset($texte[$marker])) {
            [$art, $text] = $texte[$marker];
            $html .= $this->kasten($art, htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
        }

        $bericht = $_SESSION['mitglieder_konten_bericht'] ?? null;
        unset($_SESSION['mitglieder_konten_bericht']);
        if (is_array($bericht)) {
            $zeilen = sprintf(
                '%d Konto/Konten angelegt, %d übersprungen.',
                (int)$bericht['angelegt'],
                (int)$bericht['uebersprungen']
            );
            foreach ((array)($bericht['fehler'] ?? []) as $fehler) {
                $zeilen .= '<br>' . htmlspecialchars((string)$fehler, ENT_QUOTES, 'UTF-8');
            }
            $html .= $this->kasten(empty($bericht['fehler']) ? 'ok' : 'fehler', $zeilen);
        }

        return $html;
    }

    private function kasten(string $art, string $inhaltHtml): string {
        $farbe = $art === 'ok' ? 'success' : 'danger';

        return "<div class='card' style='background-color: var(--{$farbe}-soft-bg); color: var(--{$farbe}-fg);'>{$inhaltHtml}</div>";
    }

    private function zugangKarte(): string {
        $csrf = htmlspecialchars(Router::generateCsrfToken(), ENT_QUOTES, 'UTF-8');
        $basis = htmlspecialchars(Konfiguration::basis(), ENT_QUOTES, 'UTF-8');
        $team = htmlspecialchars(Konfiguration::teamAdresse(), ENT_QUOTES, 'UTF-8');
        $typen = htmlspecialchars(implode(', ', Konfiguration::typIds()), ENT_QUOTES, 'UTF-8');
        $schluesselGesetzt = Konfiguration::apiKey() !== '';

        $optionen = '<option value="0">— keine —</option>';
        foreach ($this->gruppen() as $gruppe) {
            $gewaehlt = (int)$gruppe['id'] === Konfiguration::gruppeId() ? ' selected' : '';
            $hinweis = $gruppe['schreibt'] ? ' — gibt mehr als Leserechte!' : '';
            $optionen .= sprintf(
                '<option value="%d"%s>%s%s</option>',
                (int)$gruppe['id'],
                $gewaehlt,
                htmlspecialchars((string)$gruppe['name'], ENT_QUOTES, 'UTF-8'),
                $hinweis
            );
        }

        return "<div class='card'>
            <h2 style='font-size:1.15rem;margin-top:0;'>CiviCRM-Zugang</h2>
            <p style='color:var(--text-muted);'>
                Gelesen werden ausschliesslich laufende Mitgliedschaften und der zugehörige Kontakt
                (Name, Adresse). Es wird nichts übernommen und nichts zurückgeschrieben.
            </p>
            <form method='POST' action='" . Plugin::VERWALTUNG . "/zugang'>
                <input type=\"hidden\" name=\"csrf_token\" value=\"{$csrf}\">
                <label for='basis_url' style='display:block;font-weight:bold;'>Basis-Adresse</label>
                <input type='url' id='basis_url' name='basis_url' value='{$basis}' placeholder='https://civicrm.example.org' style='width:100%;padding:0.5rem;'>

                <label for='api_key' style='display:block;font-weight:bold;margin-top:0.8rem;'>API-Schlüssel</label>
                <input type='password' id='api_key' name='api_key' autocomplete='off' placeholder='" . ($schluesselGesetzt ? 'gesetzt — leer lassen, um ihn zu behalten' : 'noch nicht gesetzt') . "' style='width:100%;padding:0.5rem;'>
                <small style='color:var(--text-muted);'>Wird verschlüsselt gespeichert und nie wieder angezeigt.</small>

                <label for='gruppe' style='display:block;font-weight:bold;margin-top:0.8rem;'>Gruppe für neue Konten</label>
                <select id='gruppe' name='gruppe' style='width:100%;padding:0.5rem;'>{$optionen}</select>
                <small style='color:var(--text-muted);'>
                    Muss eine reine <em>Lese</em>-Gruppe sein: Mitglieder ohne eigene E-Mail-Adresse
                    lassen sich nur so anlegen (Framework#348).
                </small>

                <label for='team_email' style='display:block;font-weight:bold;margin-top:0.8rem;'>Adresse des Verwaltungsteams</label>
                <input type='email' id='team_email' name='team_email' value='{$team}' style='width:100%;padding:0.5rem;'>
                <small style='color:var(--text-muted);'>Dorthin gehen die Zugangsdaten für Mitglieder ohne eigene Adresse.</small>

                <label for='typen' style='display:block;font-weight:bold;margin-top:0.8rem;'>Mitgliedschaftsarten (IDs, leer = alle)</label>
                <input type='text' id='typen' name='typen' value='{$typen}' placeholder='z. B. 1, 3' style='width:100%;padding:0.5rem;'>

                <button type='submit' class='btn' style='margin-top:1rem;'>Speichern</button>
            </form>
        </div>";
    }

    private function vorschauKarte(): string {
        $vorschau = Abgleich::vorschau();

        if ($vorschau['fehler'] !== null) {
            return $this->kasten('fehler', htmlspecialchars($vorschau['fehler'], ENT_QUOTES, 'UTF-8'));
        }

        $zeilen = $vorschau['zeilen'];
        if ($zeilen === []) {
            return "<div class='card'><h2 style='font-size:1.15rem;margin-top:0;'>Vorschau</h2>
                <p style='color:var(--text-muted);'>CiviCRM meldet keine laufende Mitgliedschaft.</p></div>";
        }

        $zaehler = ['neu' => 0, 'vorhanden' => 0, 'blockiert' => 0];
        $tabelle = '';
        $gezeigt = 0;

        foreach ($zeilen as $zeile) {
            $zaehler[$zeile['zustand']]++;

            // Nur die anlegbaren bekommen eine Zeile mit Kaestchen. Der Rest
            // steht in der Zusammenfassung - eine Liste mit 1.400 bereits
            // vorhandenen Konten hilft niemandem.
            if ($zeile['zustand'] === 'vorhanden' || $gezeigt >= Abgleich::MAX_JE_STAPEL * 3) {
                continue;
            }
            $gezeigt++;

            $anlegbar = $zeile['zustand'] === 'neu';
            $kaestchen = $anlegbar
                ? sprintf("<input type='checkbox' name='membership_ids[]' value='%d' checked>", (int)$zeile['membership_id'])
                : '—';
            $grund = $anlegbar
                ? ($zeile['email'] === '' ? '<em>ohne eigene Adresse — geht ans Verwaltungsteam</em>' : '')
                : htmlspecialchars((string)$zeile['grund'], ENT_QUOTES, 'UTF-8');

            $tabelle .= sprintf(
                "<tr><td style='padding:0.3rem 0.6rem 0.3rem 0'>%s</td>"
                . "<td style='padding:0.3rem 0.6rem 0.3rem 0'>%d</td>"
                . "<td style='padding:0.3rem 0.6rem 0.3rem 0'>%s</td>"
                . "<td style='padding:0.3rem 0.6rem 0.3rem 0'>%s</td>"
                . "<td style='padding:0.3rem 0'>%s</td></tr>",
                $kaestchen,
                (int)$zeile['membership_id'],
                htmlspecialchars((string)$zeile['name'], ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string)$zeile['email'], ENT_QUOTES, 'UTF-8'),
                $grund
            );
        }

        $csrf = htmlspecialchars(Router::generateCsrfToken(), ENT_QUOTES, 'UTF-8');
        $deckel = Abgleich::MAX_JE_STAPEL;

        return "<div class='card' style='margin-top:1.5rem;'>
            <h2 style='font-size:1.15rem;margin-top:0;'>Vorschau</h2>
            <p style='color:var(--text-muted);'>
                <strong>{$zaehler['neu']}</strong> anlegbar &middot;
                <strong>{$zaehler['vorhanden']}</strong> haben schon ein Konto &middot;
                <strong>{$zaehler['blockiert']}</strong> gehen nicht.
                Je Durchgang werden höchstens <strong>{$deckel}</strong> Konten angelegt.
            </p>
            <form method='POST' action='" . Plugin::VERWALTUNG . "/anlegen'
                  data-confirm='Ausgewählte Konten jetzt anlegen? Zugangsdaten gehen unmittelbar heraus.'>
                <input type=\"hidden\" name=\"csrf_token\" value=\"{$csrf}\">
                <div style='overflow-x:auto;'>
                <table style='width:100%;border-collapse:collapse;'>
                    <tr><th></th><th align='left'>Mitgliedschaft</th><th align='left'>Name</th>
                        <th align='left'>E-Mail</th><th align='left'>Hinweis</th></tr>
                    {$tabelle}
                </table>
                </div>
                <button type='submit' class='btn' style='margin-top:1rem;'>Ausgewählte Konten anlegen</button>
            </form>
        </div>";
    }

    /** @return array<int, array{id:int, name:string, schreibt:bool}> */
    private function gruppen(): array {
        $db = Database::getInstance();
        $pflicht = EmailRequirement::groupIdsRequiringEmail($db);

        $zeilen = $db->query(
            "SELECT id, name FROM `groups` WHERE slug NOT IN ('public') ORDER BY is_builtin DESC, name ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map(
            static fn(array $z): array => [
                'id' => (int)$z['id'],
                'name' => (string)$z['name'],
                'schreibt' => in_array((int)$z['id'], $pflicht, true),
            ],
            $zeilen
        );
    }

    private function pruefeCsrf(): void {
        if (!Router::verifyCsrfToken(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }
    }

    private function zurueck(string $status): never {
        header('Location: ' . Plugin::VERWALTUNG . '?mk=' . $status);
        exit;
    }
}
