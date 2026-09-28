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
//     Endet eine Mitgliedschaft und tritt jemand später neu ein, vergibt
//     CiviCRM eine NEUE Mitgliedschafts-ID. Seit 1.1.0 (Audit N29) erkennt
//     die Vorschau das über die CiviCRM-Kontakt-ID und ÜBERNIMMT das alte
//     Konto: Die Zuordnung wird auf die neue Mitgliedschaft umgehängt, das
//     Konto nach denselben Regeln wie im Tageslauf entsperrt. Der
//     Benutzername bleibt die ALTE Mitgliedschafts-ID - ein zweites Konto
//     scheiterte ohnehin an der eindeutigen E-Mail-Adresse. Läuft die alte
//     Mitgliedschaft noch (oder ist ihr Status unklar), ist die Zeile
//     blockiert.
//
//  4. ENDET DIE MITGLIEDSCHAFT, WIRD GESPERRT - NIE GELÖSCHT. Der tägliche
//     Lauf fragt den Status der zugeordneten Mitgliedschaften gezielt per ID
//     ab (Audit N30) - ohne Typfilter, denn der gilt nur für die Anlage - und
//     setzt `deactivated_at` nur bei ausdrücklich "läuft nicht" oder einer
//     in CiviCRM nicht mehr auffindbaren ID. Eine Zeile ohne Statusfeld ist
//     "unklar" und sperrt nicht. Läuft eine Mitgliedschaft wieder, wird ein
//     Konto mit Grund `membership_ended` automatisch entsperrt - aber nur,
//     wenn es ausschließlich in Lesegruppen ist. Mehr als 20 % (mindestens
//     10) Sperren oder Entsperrungen auf einmal hält der Lauf an; ein ADMIN
//     bestätigt auf der Verwaltungsseite genau die angezeigte Menge. Eine
//     Sperre ist umkehrbar, eine Löschung nimmt Zuordnungen und Spuren mit
//     (Framework#358).
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
//  7. DEN CIVICRM-ZUGANG RICHTET NUR EIN ADMIN EIN (seit 1.1.0). Wer URL
//     und Schlüssel setzt, bestimmt die Datenquelle für Vorschau, Anlage und
//     Tageslauf - mit einer eigenen Quelle liessen sich Massensperren
//     auslösen. `mitglieder_konten.manage` darf die Seite sehen, die
//     Vorschau ansehen und Konten in der vom Admin gewählten Lesegruppe
//     anlegen. Der Schlüssel ist an die Basis-Adresse gebunden (Audit M5):
//     Wer die Adresse ändert, muss ihn neu eingeben, und er geht nur an
//     https-Ziele mit öffentlicher Adresse.
//
//  8. VERSANDFEHLER WERDEN GEMELDET (Audit N31) - in der Verwaltung und im
//     Protokoll, mit Benutzernamen, nie mit Passwort. Einen Weg "Zugangsdaten
//     neu erzeugen und versenden" gibt es noch nicht (Folge-Issue).
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
                // Seit 1.1.0 ohne "Zugang pflegen": Den CiviCRM-Zugang richtet
                // nur ein Admin ein (siehe VerwaltungController::zugang()).
                'label' => 'Mitglieder-Konten ansehen und anlegen',
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
            // Nur Admin (N30) - die Pruefung steht im Controller.
            ['method' => 'POST', 'path' => '/verwaltung/sperren-bestaetigen', 'callback' => [VerwaltungController::class, 'sperrenBestaetigen']],
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
 * Die Einstellungen. Sieben Werte, deshalb kein eigenes Schema - das Register
 * `owns` zaehlt sie auf und entfernt sie beim Deinstallieren.
 */
final class Konfiguration {

    public const S_URL = 'plugin_mitglieder_konten_url';
    public const S_KEY = 'plugin_mitglieder_konten_key';
    public const S_GRUPPE = 'plugin_mitglieder_konten_gruppe';
    public const S_TEAM = 'plugin_mitglieder_konten_team_email';
    public const S_TYPEN = 'plugin_mitglieder_konten_typen';

    /**
     * SHA-256 der normalisierten Basis-Adresse, fuer die der Schluessel
     * eingegeben wurde (Audit M5). Ohne diese Bindung ging der gespeicherte
     * Schluessel an JEDE neu eingetragene Adresse - wer die Adresse aendern
     * durfte, konnte ihn sich an einen eigenen Server schicken lassen.
     */
    public const S_KEY_BINDUNG = 'plugin_mitglieder_konten_key_bindung';

    /** Vom Tageslauf angehaltene Massenaenderung, JSON (Audit N30). */
    public const S_ANGEHALTEN = 'plugin_mitglieder_konten_sperre_angehalten';

    /** Alle Schluessel - daraus entsteht die IN-Liste in alle(). */
    private const ALLE = [
        self::S_URL, self::S_KEY, self::S_GRUPPE, self::S_TEAM, self::S_TYPEN,
        self::S_KEY_BINDUNG, self::S_ANGEHALTEN,
    ];

    /** @var array<string, string>|null */
    private static ?array $cache = null;

    private function __construct() {}

    /** @return array<string, string> */
    private static function alle(): array {
        if (self::$cache !== null) {
            return self::$cache;
        }

        try {
            $platzhalter = implode(', ', array_fill(0, count(self::ALLE), '?'));
            $stmt = Database::getInstance()->prepare(
                "SELECT setting_key, setting_value FROM settings WHERE setting_key IN ({$platzhalter})"
            );
            $stmt->execute(self::ALLE);
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

        // M5, Schutz in der Tiefe: Passt die gespeicherte Bindung nicht zur
        // aktuellen Adresse (etwa weil S_URL an zugang() vorbei geaendert
        // wurde), wird der Schluessel nicht herausgegeben.
        $bindung = (string)(self::alle()[self::S_KEY_BINDUNG] ?? '');
        if ($bindung === '') {
            // Altbestand vor 1.1.0: einmalig an die jetzige Adresse binden.
            // Scheitert das Schreiben, gilt der Schluessel trotzdem - sonst
            // stuende ein Update mit einer schreibgeschuetzten settings-Zeile
            // ohne Tageslauf da.
            try {
                self::speichern([self::S_KEY_BINDUNG => self::bindung(self::basis())]);
            } catch (\Throwable) {
                // bewusst still - siehe oben
            }
        } elseif (!hash_equals($bindung, self::bindung(self::basis()))) {
            return '';
        }

        return (string)(Crypto::decrypt($roh) ?? '');
    }

    /**
     * Ob ein Schluessel gespeichert ist - nach dem ROHWERT, nicht nach
     * apiKey(). apiKey() liefert bei fremder Bindung '' und oeffnete sonst
     * den Weg "kein Schluessel da, also darf die Adresse frei geaendert
     * werden" (M5).
     */
    public static function schluesselGespeichert(): bool {
        return trim((string)(self::alle()[self::S_KEY] ?? '')) !== '';
    }

    /** Die gespeicherte Bindung ('' = Altbestand ohne Bindung). */
    public static function gespeicherteBindung(): string {
        return (string)(self::alle()[self::S_KEY_BINDUNG] ?? '');
    }

    /** Bindungswert einer Basis-Adresse: SHA-256 der normalisierten Form (M5). */
    public static function bindung(string $basis): string {
        return hash('sha256', strtolower(rtrim(trim($basis), '/')));
    }

    /**
     * Die angehaltene Massenaenderung (N30), je Richtung.
     *
     * @return array<string, array{richtung:string, ids:array<int,int>, anzahl:int, grenze:int, zeit:string}>
     */
    public static function angehalten(): array {
        $roh = (string)(self::alle()[self::S_ANGEHALTEN] ?? '');
        if ($roh === '') {
            return [];
        }
        $daten = json_decode($roh, true);
        if (!is_array($daten)) {
            return [];
        }

        $ergebnis = [];
        foreach (['sperren', 'entsperren'] as $richtung) {
            $eintrag = $daten[$richtung] ?? null;
            if (!is_array($eintrag) || !is_array($eintrag['ids'] ?? null)) {
                continue;
            }
            $ids = array_values(array_unique(array_map('intval', $eintrag['ids'])));
            sort($ids);
            $ergebnis[$richtung] = [
                'richtung' => $richtung,
                'ids' => $ids,
                'anzahl' => (int)($eintrag['anzahl'] ?? count($ids)),
                'grenze' => (int)($eintrag['grenze'] ?? 0),
                'zeit' => (string)($eintrag['zeit'] ?? ''),
            ];
        }

        return $ergebnis;
    }

    /**
     * Fingerabdruck der angehaltenen Menge. Das Bestaetigungsformular traegt
     * ihn mit: Hat ein spaeterer Lauf die Menge ersetzt, passt er nicht mehr,
     * und die Bestaetigung gilt nicht fuer etwas, das der Admin nie gesehen
     * hat.
     */
    public static function angehaltenFingerabdruck(): string {
        $angehalten = self::angehalten();
        if ($angehalten === []) {
            return '';
        }
        $teile = [];
        foreach ($angehalten as $richtung => $eintrag) {
            $teile[] = $richtung . ':' . implode(',', $eintrag['ids']);
        }

        return hash('sha256', implode('|', $teile));
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
     * Prueft und normalisiert die Basis-URL - rein syntaktisch, ohne DNS.
     *
     * Nur https (M5): Der Schluessel geht als Kopfzeile mit, und ueber http
     * laege er offen im Netz. Keine Zugangsdaten, keine Query, kein Fragment:
     * Die Adresse wird zu einem API-Endpunkt zusammengesetzt, und eine Basis
     * mit eigener Query fuehrte woanders hin als gedacht. Ein PFAD ist
     * erlaubt, weil CiviCRM oft in einem Unterverzeichnis liegt.
     *
     * `localhost` und literale nicht-oeffentliche IPs fallen schon hier
     * heraus, damit der Fehler beim Speichern sichtbar wird. Numerische
     * Hostformen wie `2130706433` oder `0177.0.0.1` sind fuer filter_var
     * keine IPs - die faengt die Laufzeitpruefung nach der Aufloesung ab
     * (CiviApi::zielPruefen()).
     */
    public static function pruefeBasis(string $eingabe): ?string {
        $eingabe = trim($eingabe);
        if ($eingabe === '') {
            return '';
        }

        $teile = parse_url($eingabe);
        if (!is_array($teile) || strtolower((string)($teile['scheme'] ?? '')) !== 'https') {
            return null;
        }
        if (isset($teile['user']) || isset($teile['pass'])) {
            return null;
        }
        if (($teile['host'] ?? '') === '' || ($teile['query'] ?? '') !== '' || ($teile['fragment'] ?? '') !== '') {
            return null;
        }
        // parse_url laesst ein leeres '?' oder '#' als leeren Wert stehen.
        if (str_contains($eingabe, '?') || str_contains($eingabe, '#')) {
            return null;
        }

        $host = strtolower(rtrim((string)$teile['host'], '.'));
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return null;
        }
        $literal = (str_starts_with($host, '[') && str_ends_with($host, ']')) ? substr($host, 1, -1) : $host;
        if (filter_var($literal, FILTER_VALIDATE_IP) !== false && !CiviApi::ipIstOeffentlich($literal)) {
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
final class Zielgruppe {

    public const FEHLER = 'Die Gruppe für neue Konten ist keine reine Lesegruppe oder existiert nicht mehr. '
                        . 'Bitte unter „CiviCRM-Zugang“ eine Gruppe wählen, die ausschließlich Leserechte hat.';

    private function __construct() {}

    /**
     * Welche Gruppen neue Konten bekommen duerfen: nur reine Lesegruppen.
     *
     * WARUM SERVERSEITIG UND AN MEHREREN STELLEN: Das Recht
     * `mitglieder_konten.manage` laesst sich an Nicht-Admins vergeben, etwa
     * an eine Geschaeftsstelle. Im Kern legt dagegen nur ein Admin Konten an
     * und weist Gruppen zu (UserController: requireAdmin()), und
     * UserProvisioning filtert bewusst nur `public`. Stuende hier jede Gruppe
     * zur Wahl, machte das Addon-Recht aus Mitgliedern Administratoren
     * (Audit H1). Geprueft wird beim Speichern UND beim Anlegen: Eine Gruppe
     * kann nach dem Speichern Schreibrechte bekommen, und aeltere Fassungen
     * haben jede Gruppe gespeichert.
     *
     * `admin` faellt doppelt heraus - per SQL und ueber EmailRequirement. Ein
     * Irrtum an dieser Stelle ergaebe ein Vollverwalter-Konto.
     *
     * @return array<int, array{id:int, name:string}>
     */
    public static function zulaessige(PDO $db): array {
        $pflicht = EmailRequirement::groupIdsRequiringEmail($db);

        $zeilen = $db->query(
            "SELECT id, name FROM `groups` WHERE slug NOT IN ('admin', 'public') ORDER BY is_builtin DESC, name ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $erlaubt = [];
        foreach ($zeilen as $z) {
            if (in_array((int)$z['id'], $pflicht, true)) {
                continue;
            }
            $erlaubt[] = ['id' => (int)$z['id'], 'name' => (string)$z['name']];
        }

        return $erlaubt;
    }

    /** 0 = "keine Gruppe" ist zulaessig. Im Zweifel nein (fail-closed). */
    public static function istZulaessig(PDO $db, int $gruppeId): bool {
        if ($gruppeId === 0) {
            return true;
        }
        if ($gruppeId < 0) {
            return false;
        }

        try {
            return in_array($gruppeId, array_column(self::zulaessige($db), 'id'), true);
        } catch (\Throwable) {
            return false;
        }
    }
}

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

    public static function entsperrvermerk(int $membershipId): void {
        $stmt = Database::getInstance()->prepare(
            'UPDATE `' . self::TABELLE . '` SET gesperrt_am = NULL WHERE membership_id = ?'
        );
        $stmt->execute([$membershipId]);
    }

    /**
     * Je CiviCRM-Kontakt die juengste Zuordnung - Grundlage fuer die
     * Erkennung eines Wiedereintritts (N29).
     *
     * @return array<int, array{membership_id:int, user_id:int}> contact_id => Zuordnung
     */
    public static function nachKontakt(): array {
        try {
            $zeilen = Database::getInstance()
                ->query(
                    'SELECT membership_id, user_id, civicrm_contact_id FROM `' . self::TABELLE . '`
                     ORDER BY angelegt_am ASC, membership_id ASC'
                )
                ->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }

        $ergebnis = [];
        foreach ((array)$zeilen as $z) {
            // Spaetere Zeilen ueberschreiben fruehere: Die juengste gewinnt.
            $ergebnis[(int)$z['civicrm_contact_id']] = [
                'membership_id' => (int)$z['membership_id'],
                'user_id' => (int)$z['user_id'],
            ];
        }

        return $ergebnis;
    }

    /**
     * Haengt eine Zuordnung auf eine neue Mitgliedschafts-ID um (N29).
     *
     * Ein UPDATE des Primaerschluessels - es gibt keine Fremdschluessel auf
     * membership_id, und uq_mk_user bleibt erfuellt, weil sich user_id nicht
     * aendert. rowCount() === 1 ist die Pruefung gegen einen parallelen Lauf:
     * Hat ihn ein anderer schon umgehaengt, trifft das UPDATE nichts.
     */
    public static function umhaengen(int $alt, int $neu): bool {
        $stmt = Database::getInstance()->prepare(
            'UPDATE `' . self::TABELLE . '` SET membership_id = ? WHERE membership_id = ?'
        );
        $stmt->execute([$neu, $alt]);

        return $stmt->rowCount() === 1;
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

    /**
     * Plausibilitaetsgrenze des Tageslaufs (N30): Mehr als dieser Anteil der
     * aktiven (bzw. gesperrten) Zuordnungen auf einmal - mindestens aber
     * AENDER_MIN_ABSOLUT - ist kein Alltag, sondern ein Fehler der Quelle
     * oder ein Angriff. Dann haelt der Lauf an, und ein Admin bestaetigt.
     */
    public const AENDER_MIN_ABSOLUT = 10;
    public const AENDER_HOECHSTANTEIL = 0.2;

    /** Sperrgrund dieses Addons - nur solche Sperren hebt es selbst wieder auf. */
    public const GRUND = 'membership_ended';

    private function __construct() {}

    /**
     * Was ein Anlagelauf taete - VOR dem ersten Konto.
     *
     * Jede Zeile traegt ihren Zustand: `neu` (wird angelegt), `vorhanden`
     * (hat schon ein Konto), `wiedereintritt` (das fruehere Konto desselben
     * CiviCRM-Kontakts wird uebernommen, N29) oder `blockiert` mit Grund.
     * Die Hinderungsgruende sind genau die, an denen die Anlage scheitern
     * wuerde - belegter Benutzername, unzulaessiger Name, eine E-Mail-Adresse,
     * die schon einem Konto gehoert (UNIQUE in `users`), dieselbe Adresse
     * zweimal im Stapel. Sie hier zu zeigen ist der Unterschied zwischen
     * "1.496 Konten, 37 Fehler im Protokoll" und "37 Faelle, die vorher zu
     * klaeren sind".
     *
     * @return array{zeilen: array<int, array<string, mixed>>, fehler: ?string}
     */
    public static function vorschau(?CiviApi $client = null): array {
        $client ??= Konfiguration::client();

        if (!$client->eingerichtet()) {
            return ['zeilen' => [], 'fehler' => 'Der CiviCRM-Zugang ist noch nicht eingerichtet.'];
        }

        // VOR dem Abruf: Bei unzulaessiger Zielgruppe geht keine Anfrage an
        // CiviCRM heraus, und die Vorschau bietet nichts zum Anlegen an (H1).
        if (!Zielgruppe::istZulaessig(Database::getInstance(), Konfiguration::gruppeId())) {
            return ['zeilen' => [], 'fehler' => Zielgruppe::FEHLER];
        }

        try {
            $mitgliedschaften = $client->laufendeMitgliedschaften(Konfiguration::typIds());
        } catch (CiviApiFehler $e) {
            $e->protokollieren();
            return ['zeilen' => [], 'fehler' => $e->getMessage()];
        }

        // Vor der Pruefung sortiert: Die Regel "dieselbe Adresse zweimal im
        // Stapel - die spaetere ist blockiert" braucht eine feste Reihenfolge.
        usort($mitgliedschaften, static fn(array $a, array $b): int => $a['membership_id'] <=> $b['membership_id']);

        $bekannt = Zuordnung::alle();
        $nachKontakt = Zuordnung::nachKontakt();
        $belegt = self::belegteBenutzernamen();
        $adressen = self::belegteAdressen();

        // N29: Kandidaten fuer einen Wiedereintritt sammeln und den Status
        // ihrer ALTEN Mitgliedschaft in EINEM Aufruf erfragen - ohne
        // Typfilter. Die typgefilterte Liste oben taugt dafuer nicht: Eine
        // noch laufende Mitgliedschaft einer anderen Art fehlt dort und sahe
        // wie beendet aus.
        $alteIds = [];
        $altUserIds = [];
        foreach ($mitgliedschaften as $m) {
            $frueher = $nachKontakt[$m['contact_id']] ?? null;
            if (!isset($bekannt[$m['membership_id']]) && $frueher !== null && $frueher['membership_id'] !== $m['membership_id']) {
                $alteIds[] = $frueher['membership_id'];
                $altUserIds[] = $frueher['user_id'];
            }
        }
        $alterStatus = [];
        $altkonten = [];
        if ($alteIds !== []) {
            try {
                $alterStatus = $client->statusNachId($alteIds);
            } catch (CiviApiFehler $e) {
                $e->protokollieren();
                return ['zeilen' => [], 'fehler' => $e->getMessage()];
            }
            $altkonten = self::kontenZustand($altUserIds);
        }

        $zeilen = [];
        $imLauf = [];
        $uebernommen = [];
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
            $frueher = $nachKontakt[$m['contact_id']] ?? null;
            $adresse = mb_strtolower(trim((string)$m['email']), 'UTF-8');

            if (isset($bekannt[$m['membership_id']])) {
                $zeile['zustand'] = 'vorhanden';
            } elseif ($frueher !== null && $frueher['membership_id'] !== $m['membership_id']) {
                $alt = $frueher['membership_id'];
                $konto = $altkonten[$frueher['user_id']] ?? null;
                // Fehlt die alte ID in CiviCRM, gilt sie als beendet - wie im
                // Tageslauf. true und null ("unklar") blockieren.
                $laeuftNoch = array_key_exists($alt, $alterStatus) && $alterStatus[$alt] !== false;

                if ($laeuftNoch) {
                    $zeile['zustand'] = 'blockiert';
                    $zeile['grund'] = sprintf('Der Kontakt hat bereits ein Konto über Mitgliedschaft %d.', $alt);
                } elseif ($konto === null || $konto['deleted_at'] !== null) {
                    $zeile['zustand'] = 'blockiert';
                    $zeile['grund'] = 'Früheres Konto wurde gelöscht, hält die Adresse aber noch - ein Admin muss es endgültig entfernen.';
                } elseif (isset($uebernommen[$frueher['user_id']])) {
                    // Zwei neue Mitgliedschaften desselben Kontakts: Das alte
                    // Konto kann nur eine davon uebernehmen.
                    $zeile['zustand'] = 'blockiert';
                    $zeile['grund'] = sprintf('Der Kontakt hat bereits ein Konto über Mitgliedschaft %d.', $uebernommen[$frueher['user_id']]);
                } else {
                    $zeile['zustand'] = 'wiedereintritt';
                    $zeile['grund'] = sprintf('Früheres Konto %s wird übernommen.', $konto['username']);
                    $zeile['user_id'] = $frueher['user_id'];
                    $zeile['alte_membership_id'] = $alt;
                    $uebernommen[$frueher['user_id']] = $m['membership_id'];
                }
            } elseif (isset($belegt[mb_strtolower($benutzername, 'UTF-8')])) {
                $zeile['zustand'] = 'blockiert';
                $zeile['grund'] = 'Der Benutzername ist bereits vergeben - nicht durch dieses Addon.';
            } elseif (LoginIdentifier::usernameErrors($benutzername) !== []) {
                $zeile['zustand'] = 'blockiert';
                $zeile['grund'] = implode(' ', LoginIdentifier::usernameErrors($benutzername));
            } elseif ($adresse !== '' && isset($adressen[$adresse])) {
                // N29: `users.email` ist UNIQUE - auch fuer soft-geloeschte
                // Konten. Bisher scheiterte das erst beim Anlegen.
                $zeile['zustand'] = 'blockiert';
                $zeile['grund'] = 'Die E-Mail-Adresse gehört bereits zu einem anderen Konto (z. B. gemeinsame Familienadresse).';
            } elseif ($adresse !== '' && isset($imLauf[$adresse])) {
                $zeile['zustand'] = 'blockiert';
                $zeile['grund'] = sprintf('Dieselbe E-Mail-Adresse hat schon Mitgliedschaft %d in dieser Liste.', $imLauf[$adresse]);
            }
            // Mitglieder ohne Adresse brauchen keinen eigenen Zweig: Die
            // Zielgruppe ist oben bereits auf eine reine Lesegruppe
            // festgelegt, und genau die verlangt Framework#348 fuer Konten
            // ohne Adresse. UserProvisioning prueft das beim Anlegen ohnehin.

            if ($zeile['zustand'] === 'neu' && $adresse !== '') {
                $imLauf[$adresse] = $m['membership_id'];
            }
            $zeilen[] = $zeile;
        }

        return ['zeilen' => $zeilen, 'fehler' => null];
    }

    /**
     * Legt die ausgewaehlten Konten an bzw. uebernimmt fruehere Konten bei
     * einem Wiedereintritt (N29).
     *
     * Die erzeugten Passwoerter verlassen diese Methode nicht - sie gehen
     * nur in die Zustellung (N31: Das fruehere Feld `zugangsdaten` ist
     * entfallen, niemand ausserhalb brauchte es).
     *
     * @param array<int, int> $membershipIds
     * @return array{angelegt: int, reaktiviert: int, uebersprungen: int, fehler: array<int, string>}
     */
    public static function anlegen(array $membershipIds, ?CiviApi $client = null, ?Mailer $mailer = null): array {
        $client ??= Konfiguration::client();
        $ergebnis = ['angelegt' => 0, 'reaktiviert' => 0, 'uebersprungen' => 0, 'fehler' => []];

        $auswahl = array_slice(array_values(array_unique(array_map('intval', $membershipIds))), 0, self::MAX_JE_STAPEL);
        if ($auswahl === []) {
            return $ergebnis;
        }

        // Eigene Pruefung, unabhaengig von vorschau(): Tiefenverteidigung
        // gegen H1. Die Gruppe wird genau einmal gelesen und fuer den ganzen
        // Stapel verwendet - kein Wechsel zwischen Pruefung und Anlage.
        $db = Database::getInstance();
        $gruppe = Konfiguration::gruppeId();
        if (!Zielgruppe::istZulaessig($db, $gruppe)) {
            $ergebnis['fehler'][] = Zielgruppe::FEHLER;
            $ergebnis['uebersprungen'] = count($auswahl);
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

        $zugangsdaten = [];
        foreach ($auswahl as $membershipId) {
            $zeile = $nachId[$membershipId] ?? null;

            // Die Auswahl kommt aus einem Formular und ist damit
            // nutzergesteuert: Was in der Vorschau nicht als `neu` oder
            // `wiedereintritt` steht, wird nicht angefasst - auch wenn es im
            // POST steht.
            if ($zeile !== null && $zeile['zustand'] === 'wiedereintritt') {
                self::uebernehmen($db, $zeile, $ergebnis);
                continue;
            }
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
            $zugangsdaten[] = [
                'benutzername' => (string)$zeile['benutzername'],
                'passwort' => $passwort,
                'email' => (string)$zeile['email'],
                'name' => (string)$zeile['name'],
            ];
        }

        foreach (self::zugangsdatenZustellen($zugangsdaten, $mailer) as $fehler) {
            $ergebnis['fehler'][] = $fehler;
        }

        return $ergebnis;
    }

    /**
     * Wiedereintritt (N29): die Zuordnung auf die neue Mitgliedschaft
     * umhaengen und das Konto nach denselben Regeln wie im Tageslauf
     * entsperren. Kein neues Passwort, keine Mail, kein Abgleich der Adresse
     * (Punkt 1 im Dateikopf) - das Mitglied meldet sich mit seinen alten
     * Zugangsdaten an.
     *
     * @param array<string, mixed> $zeile
     * @param array{angelegt: int, reaktiviert: int, uebersprungen: int, fehler: array<int, string>} $ergebnis
     */
    private static function uebernehmen(PDO $db, array $zeile, array &$ergebnis): void {
        $neu = (int)$zeile['membership_id'];
        $alt = (int)$zeile['alte_membership_id'];
        $userId = (int)$zeile['user_id'];

        $db->beginTransaction();
        try {
            if (!Zuordnung::umhaengen($alt, $neu)) {
                // Parallel geaendert - lieber nichts tun als raten.
                $db->rollBack();
                $ergebnis['uebersprungen']++;
                return;
            }

            $konto = self::kontenZustand([$userId])[$userId] ?? null;
            $gesperrt = $konto !== null && $konto['deactivated_at'] !== null;
            $entsperrt = $gesperrt && self::entsperren($userId, $neu);
            if (!$gesperrt) {
                Zuordnung::entsperrvermerk($neu);
            }

            AuditLogger::log(
                'Konto übernommen (Wiedereintritt)',
                'users',
                sprintf('Benutzer-ID %d, Mitgliedschaft alt %d -> neu %d%s', $userId, $alt, $neu, $gesperrt && !$entsperrt ? ', bleibt gesperrt' : '')
            );
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[mitglieder-konten] Wiedereintritt fehlgeschlagen: ' . $e->getMessage());
            $ergebnis['fehler'][] = sprintf('Mitgliedschaft %d: Das frühere Konto konnte nicht übernommen werden.', $neu);
            return;
        }

        $ergebnis['reaktiviert']++;
        if ($gesperrt && !$entsperrt) {
            // Gesperrt aus anderem Grund (Admin, Ruhesperre) oder in einer
            // schreibenden Gruppe: Das entscheidet ein Mensch, nicht CiviCRM.
            $ergebnis['fehler'][] = sprintf('Konto %s bleibt gesperrt - bitte durch einen Admin prüfen.', (string)($konto['username'] ?? $userId));
        }
    }

    /**
     * Zustellung: an das Mitglied, wenn es eine Adresse hat - sonst gesammelt
     * an das Verwaltungsteam.
     *
     * GESAMMELT und nicht je Konto: Bei einem Stapel ohne Adressen waeren das
     * sonst hundert Mails an dieselbe Stelle, und die hundertste geht unter.
     *
     * N31: Jeder Fehlschlag wird gemeldet - als EIN Sammeltext je Fall mit
     * den Benutzernamen, und im Audit-Log. NIE mit Passwort: Das Protokoll
     * lesen mehr Leute als die, fuer die das Passwort bestimmt ist.
     *
     * @param array<int, array<string, string>> $daten
     * @return array<int, string> Fehlertexte fuer die Verwaltung
     */
    private static function zugangsdatenZustellen(array $daten, ?Mailer $mailer = null): array {
        if ($daten === []) {
            return [];
        }

        $fehler = [];
        try {
            $mailer ??= new Mailer();
        } catch (\Throwable $e) {
            // Der Konstruktor liest `settings` - scheitert das, geht gar nichts heraus.
            error_log('[mitglieder-konten] Mailer nicht verfuegbar: ' . $e->getMessage());
            $namen = array_column($daten, 'benutzername');
            self::zustellungsfehler('Mailversand nicht verfuegbar', $namen);
            return [sprintf(
                'Zugangsdaten konnten nicht versendet werden für: %s - Passwort durch einen Admin neu setzen.',
                implode(', ', $namen)
            )];
        }

        $ohneAdresse = [];
        $gescheitert = [];
        foreach ($daten as $satz) {
            if ($satz['email'] === '') {
                $ohneAdresse[] = $satz;
                continue;
            }
            try {
                $ok = $mailer->sendWelcomeEmail($satz['email'], $satz['benutzername'], $satz['passwort']);
            } catch (\Throwable $e) {
                error_log('[mitglieder-konten] Willkommensmail gescheitert: ' . $e->getMessage());
                $ok = false;
            }
            if (!$ok) {
                $gescheitert[] = $satz['benutzername'];
            }
        }

        if ($gescheitert !== []) {
            self::zustellungsfehler('Willkommensmail nicht versendet', $gescheitert);
            $fehler[] = sprintf(
                'Zugangsdaten konnten nicht versendet werden für: %s - Passwort über „Passwort vergessen“ oder durch einen Admin neu setzen.',
                implode(', ', $gescheitert)
            );
        }

        if ($ohneAdresse === []) {
            return $fehler;
        }

        $namen = array_column($ohneAdresse, 'benutzername');
        $team = Konfiguration::teamAdresse();
        if ($team === '') {
            self::zustellungsfehler(
                'Keine Adresse des Verwaltungsteams hinterlegt - die Passwoerter sind nirgends, '
                . 'die Konten brauchen ein neu gesetztes Passwort durch einen Admin',
                $namen
            );
            $fehler[] = sprintf(
                'Keine Adresse des Verwaltungsteams hinterlegt - Zugangsdaten nicht zugestellt für: %s. Passwort durch einen Admin neu setzen.',
                implode(', ', $namen)
            );
            return $fehler;
        }

        try {
            $ok = $mailer->send($team, 'Neue Mitglieder-Konten - Zugangsdaten', self::teamMailHtml($ohneAdresse));
        } catch (\Throwable $e) {
            error_log('[mitglieder-konten] Sammelmail gescheitert: ' . $e->getMessage());
            $ok = false;
        }
        if (!$ok) {
            self::zustellungsfehler('Sammelmail an das Verwaltungsteam nicht versendet', $namen);
            $fehler[] = sprintf(
                'Sammelmail an das Verwaltungsteam nicht versendet - betroffen: %s. Passwort durch einen Admin neu setzen.',
                implode(', ', $namen)
            );
        }

        return $fehler;
    }

    /**
     * Ein Audit-Eintrag je Zustellungsfehler - nur Benutzernamen (N31).
     *
     * @param array<int, string> $benutzernamen
     */
    private static function zustellungsfehler(string $fall, array $benutzernamen): void {
        AuditLogger::log(
            'Zugangsdaten nicht zustellbar',
            'users',
            sprintf('%s. Betroffene Benutzernamen: %s', $fall, implode(', ', $benutzernamen))
        );
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
     * Handlung mit Vorschau (Addons#131). Er sperrt, was nicht mehr laeuft,
     * und entsperrt, was wieder laeuft (N30).
     *
     * WARUM SO VORSICHTIG. Frueher galt: "fehlt in der Liste der laufenden
     * Mitgliedschaften" = beendet. Eine leere Antwort (ACL, halb
     * eingerichteter API-Benutzer, Typfilter, gefaelschte Quelle) sperrte
     * damit ueber Nacht den ganzen Bestand. Jetzt:
     *  - Status gezielt per ID, ohne Typfilter (CiviApi::statusNachId()).
     *  - Gesperrt wird nur bei ausdruecklich false oder fehlender ID; eine
     *    Zeile ohne Statusfeld ist "unklar" und sperrt nicht.
     *  - Mehr als AENDER_HOECHSTANTEIL (mindestens AENDER_MIN_ABSOLUT) je
     *    Richtung haelt an. Das gilt AUCH fuers Entsperren: Eine gefaelschte
     *    Quelle koennte sonst alle beendeten Mitglieder zurueckholen.
     *  - Eine angehaltene Aenderung bestaetigt ein Admin ($bestaetigt), und
     *    die Bestaetigung gilt nur fuer die gespeicherte ID-Menge.
     *
     * @return array{geprueft: int, gesperrt: int, reaktiviert: int, unklar: int, angehalten: bool}
     */
    public static function taeglicherLauf(?CiviApi $client = null, bool $bestaetigt = false): array {
        $bericht = ['geprueft' => 0, 'gesperrt' => 0, 'reaktiviert' => 0, 'unklar' => 0, 'angehalten' => false];

        $client ??= Konfiguration::client();
        if (!$client->eingerichtet()) {
            return $bericht;
        }

        // Der Lauf legt keine Konten an - er sperrt nur. Eine unzulaessige
        // Zielgruppe wird deshalb gemeldet, bricht ihn aber NICHT ab: Die
        // Sperre beendeter Mitgliedschaften ist selbst ein Schutz.
        $zielgruppe = Konfiguration::gruppeId();
        if (!Zielgruppe::istZulaessig(Database::getInstance(), $zielgruppe)) {
            AuditLogger::log(
                'Mitglieder-Konten: Zielgruppe unzulaessig',
                'users',
                sprintf(
                    'Die gespeicherte Gruppe fuer neue Konten (ID %d) ist keine reine Lesegruppe oder existiert '
                    . 'nicht mehr. Neue Konten entstehen erst nach Auswahl einer Lesegruppe; die Sperre beendeter '
                    . 'Mitgliedschaften laeuft unveraendert.',
                    $zielgruppe
                )
            );
        }

        $zuordnungen = Zuordnung::alle();
        if ($zuordnungen === []) {
            return $bericht;
        }

        try {
            // OHNE Typfilter (N30): Der Filter "Mitgliedschaftsarten" gilt nur
            // fuer die Anlage. Mit ihm galt jede Mitgliedschaft einer anderen
            // Art als beendet.
            $status = $client->statusNachId(array_keys($zuordnungen));
        } catch (CiviApiFehler $e) {
            // Ein Umgebungsfehler ist kein Ergebnis: Waere CiviCRM
            // unerreichbar und wir deuteten das als "keine Mitgliedschaft
            // laeuft mehr", sperrte der Lauf ueber Nacht JEDES Konto.
            $e->protokollieren();
            AuditLogger::log(
                'Mitglieder-Abgleich nicht durchgefuehrt',
                'users',
                'CiviCRM war nicht erreichbar: ' . $e->getMessage() . ' - es wurde nichts gesperrt.'
            );
            return $bericht;
        }

        $db = Database::getInstance();
        $konten = self::kontenZustand(array_values($zuordnungen));

        $zuSperren = [];
        $zuEntsperren = [];
        $adminPruefen = [];
        $aktiv = 0;
        $gesperrtBestand = 0;
        foreach ($zuordnungen as $membershipId => $userId) {
            $konto = $konten[$userId] ?? null;
            if ($konto === null || $konto['deleted_at'] !== null) {
                continue;
            }
            $istAktiv = $konto['deactivated_at'] === null;
            if ($istAktiv) {
                $aktiv++;
            } else {
                $gesperrtBestand++;
            }

            if (!array_key_exists($membershipId, $status)) {
                // In CiviCRM nicht mehr auffindbar = beendet. Verschwinden
                // viele auf einmal (ACL), greift die Grenze unten.
                if ($istAktiv) {
                    $zuSperren[$membershipId] = $userId;
                }
                continue;
            }

            if ($status[$membershipId] === null) {
                $bericht['unklar']++;
            } elseif ($status[$membershipId] === false && $istAktiv) {
                $zuSperren[$membershipId] = $userId;
            } elseif ($status[$membershipId] === true && !$istAktiv && $konto['deactivated_reason'] === self::GRUND) {
                // Nur Lesegruppen: Ein Konto, das vor H1 in eine schreibende
                // Gruppe gelegt wurde, holt CiviCRM nicht zurueck.
                if (self::nurLesegruppen($db, $userId)) {
                    $zuEntsperren[$membershipId] = $userId;
                } else {
                    $adminPruefen[] = $userId;
                }
            }
        }
        $bericht['geprueft'] = count($zuordnungen);

        if ($bericht['unklar'] > 0) {
            AuditLogger::log(
                'Mitglieder-Abgleich: Status unklar',
                'users',
                sprintf('%d Mitgliedschaft(en) ohne auswertbaren Status von CiviCRM - nicht gesperrt.', $bericht['unklar'])
            );
        }
        if ($adminPruefen !== []) {
            AuditLogger::log(
                'Mitglieder-Abgleich: Entsperren durch Admin pruefen',
                'users',
                sprintf(
                    'Mitgliedschaft laeuft wieder, aber das Konto ist nicht nur in Lesegruppen - bleibt gesperrt, '
                    . 'bitte durch einen Admin pruefen. Benutzer-IDs: %s',
                    implode(', ', $adminPruefen)
                )
            );
        }

        $gespeichert = Konfiguration::angehalten();
        $neuAngehalten = [];
        $richtungen = [
            'sperren' => [$zuSperren, self::grenze($aktiv)],
            'entsperren' => [$zuEntsperren, self::grenze($gesperrtBestand)],
        ];
        $ausfuehren = ['sperren' => [], 'entsperren' => []];

        foreach ($richtungen as $richtung => [$menge, $grenze]) {
            $freigegeben = [];
            if ($bestaetigt && isset($gespeichert[$richtung])) {
                // Nur die Schnittmenge mit dem, was der Admin gesehen hat.
                // Eine Bestaetigung von "12 Sperren" darf nicht 1.400 Sperren
                // ausloesen, weil sich die Quelle zwischendurch geaendert hat.
                $freigegeben = array_intersect_key($menge, array_flip($gespeichert[$richtung]['ids']));
            }
            $rest = array_diff_key($menge, $freigegeben);

            if (count($rest) > $grenze) {
                $ids = array_keys($rest);
                sort($ids);
                $neuAngehalten[$richtung] = [
                    'richtung' => $richtung,
                    'ids' => $ids,
                    'anzahl' => count($ids),
                    'grenze' => $grenze,
                    'zeit' => date('Y-m-d H:i:s'),
                ];
                $rest = [];
                $bericht['angehalten'] = true;
                AuditLogger::log(
                    'Mitglieder-Abgleich angehalten',
                    'users',
                    sprintf(
                        '%d %s ueber der Plausibilitaetsgrenze %d - nichts geaendert, Bestaetigung durch einen Admin noetig',
                        count($ids),
                        $richtung === 'sperren' ? 'Sperren' : 'Reaktivierungen',
                        $grenze
                    )
                );
            }
            $ausfuehren[$richtung] = $freigegeben + $rest;
        }

        // Ein Lauf ohne Anhalten leert den Vermerk; einer mit Anhalten
        // ersetzt ihn durch die aktuelle Menge.
        Konfiguration::speichern([
            Konfiguration::S_ANGEHALTEN => $neuAngehalten === [] ? '' : json_encode($neuAngehalten, JSON_THROW_ON_ERROR),
        ]);

        $sperre = $db->prepare(
            'UPDATE users SET deactivated_at = NOW(), deactivated_reason = ?
             WHERE id = ? AND deleted_at IS NULL AND deactivated_at IS NULL'
        );
        foreach ($ausfuehren['sperren'] as $membershipId => $userId) {
            $sperre->execute([self::GRUND, $userId]);
            if ($sperre->rowCount() > 0) {
                Zuordnung::sperrvermerk($membershipId);
                $bericht['gesperrt']++;
                AuditLogger::log(
                    'Konto gesperrt (Mitgliedschaft beendet)',
                    'users',
                    sprintf('Benutzer-ID %d, Mitgliedschaft %d laeuft nicht mehr', $userId, $membershipId)
                );
            }
        }

        foreach ($ausfuehren['entsperren'] as $membershipId => $userId) {
            if (self::entsperren($userId, $membershipId)) {
                $bericht['reaktiviert']++;
            }
        }

        return $bericht;
    }

    /** Plausibilitaetsgrenze fuer eine Richtung bei $bestand betroffenen Konten. */
    public static function grenze(int $bestand): int {
        return max(self::AENDER_MIN_ABSOLUT, (int)ceil($bestand * self::AENDER_HOECHSTANTEIL));
    }

    /**
     * Hebt eine Sperre dieses Addons auf - gemeinsamer Weg fuer den
     * Tageslauf (N30) und den Wiedereintritt (N29).
     *
     * Nur, wenn das Konto ausschliesslich in Lesegruppen ist, und nur fuer
     * den Grund `membership_ended`: Eine Sperre durch einen Admin oder die
     * Ruhesperre (Framework#358) hebt CiviCRM nicht auf. Die Spalten sind
     * dieselben wie in DormantAccountService::reactivate() - ohne den
     * zurueckgesetzten Fristanker deaktivierte der naechste Nachtlauf das
     * Konto sofort wieder.
     */
    public static function entsperren(int $userId, int $membershipId): bool {
        $db = Database::getInstance();
        if (!self::nurLesegruppen($db, $userId)) {
            return false;
        }

        $stmt = $db->prepare(
            "UPDATE users SET deactivated_at = NULL, deactivated_reason = NULL, unprotected_since = NULL
             WHERE id = ? AND deleted_at IS NULL AND deactivated_at IS NOT NULL AND deactivated_reason = ?"
        );
        $stmt->execute([$userId, self::GRUND]);
        if ($stmt->rowCount() === 0) {
            return false;
        }

        Zuordnung::entsperrvermerk($membershipId);
        AuditLogger::log(
            'Konto entsperrt (Mitgliedschaft laeuft wieder)',
            'users',
            sprintf('Benutzer-ID %d, Mitgliedschaft %d', $userId, $membershipId)
        );

        return true;
    }

    /** Ist das Konto in keiner Gruppe mit Schreib-/Veroeffentlichungsrecht und nicht admin? Im Zweifel nein. */
    private static function nurLesegruppen(PDO $db, int $userId): bool {
        try {
            $stmt = $db->prepare('SELECT group_id FROM user_groups WHERE user_id = ?');
            $stmt->execute([$userId]);
            $gruppen = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

            return array_intersect($gruppen, EmailRequirement::groupIdsRequiringEmail($db)) === [];
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Zustand der Konten - in Bloecken, damit die IN-Liste klein bleibt.
     *
     * @param array<int, int> $userIds
     * @return array<int, array{username:string, deactivated_at:?string, deactivated_reason:?string, deleted_at:?string}>
     */
    private static function kontenZustand(array $userIds): array {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn(int $id): bool => $id > 0)));
        $ergebnis = [];
        $db = Database::getInstance();

        foreach (array_chunk($userIds, 500) as $block) {
            $platzhalter = implode(', ', array_fill(0, count($block), '?'));
            $stmt = $db->prepare(
                "SELECT id, username, deactivated_at, deactivated_reason, deleted_at FROM users WHERE id IN ({$platzhalter})"
            );
            $stmt->execute($block);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $z) {
                $ergebnis[(int)$z['id']] = [
                    'username' => (string)$z['username'],
                    'deactivated_at' => $z['deactivated_at'] !== null ? (string)$z['deactivated_at'] : null,
                    'deactivated_reason' => $z['deactivated_reason'] !== null ? (string)$z['deactivated_reason'] : null,
                    'deleted_at' => $z['deleted_at'] !== null ? (string)$z['deleted_at'] : null,
                ];
            }
        }

        return $ergebnis;
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

    /**
     * Vergebene E-Mail-Adressen, kleingeschrieben -> Benutzer-ID (N29).
     *
     * INKLUSIVE soft-geloeschter Konten: Der UNIQUE-Index auf `users.email`
     * erfasst auch sie. Klein geschrieben, weil die Kollation
     * utf8mb4_unicode_ci Gross- und Kleinschreibung nicht unterscheidet.
     *
     * @return array<string, int>
     */
    private static function belegteAdressen(): array {
        try {
            $zeilen = Database::getInstance()
                ->query('SELECT id, email FROM users WHERE email IS NOT NULL')
                ->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }

        $belegt = [];
        foreach ((array)$zeilen as $z) {
            $adresse = mb_strtolower(trim((string)$z['email']), 'UTF-8');
            if ($adresse !== '') {
                $belegt[$adresse] = (int)$z['id'];
            }
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
 *
 * Wer was darf (seit 1.1.0):
 *  - `mitglieder_konten.manage`: Seite, Vorschau, Anlage in der vom Admin
 *    gewaehlten Lesegruppe.
 *  - nur Admin: den CiviCRM-Zugang einrichten (zugang()) und eine
 *    angehaltene Massenaenderung bestaetigen (sperrenBestaetigen()).
 */
class VerwaltungController extends BaseController {

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
        $this->requirePermission(Plugin::MODUL, 'manage');
    }

    public function index(): void {
        $inhalt = $this->meldung();
        $inhalt .= $this->angehaltenKarte();
        $inhalt .= $this->zugangKarte();
        $inhalt .= $this->vorschauKarte();

        PluginPage::render('Mitglieder-Konten', $inhalt);
    }

    public function zugang(): void {
        // NUR ADMIN (Betreiberentscheidung zu M5/N30). Wer Adresse und
        // Schluessel setzt, bestimmt die Datenquelle fuer Vorschau, Anlage
        // und Tageslauf. Mit `manage` allein liesse sich eine eigene Quelle
        // samt eigenem Schluessel eintragen - und damit jede gewuenschte
        // Antwort liefern, bis hin zu Massensperren. Im Kern richtet ohnehin
        // nur ein Admin Integrationen ein.
        $this->requireAdmin();
        $this->pruefeCsrf();

        // Zuerst die Zielgruppe (H1): Bei einem unzulaessigen Wert wird die
        // ganze Eingabe verworfen - auch Adresse und Schluessel - und der
        // Versuch protokolliert.
        $gruppeRoh = $_POST['gruppe'] ?? '0';
        $gruppe = is_string($gruppeRoh)
            ? filter_var($gruppeRoh, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]])
            : false;
        if ($gruppe === false || !Zielgruppe::istZulaessig(Database::getInstance(), $gruppe)) {
            PluginAudit::log(
                Plugin::SLUG,
                'Unzulaessige Zielgruppe abgelehnt',
                'Mitglieder-Konten',
                'Angefragte Gruppen-ID: ' . (is_string($gruppeRoh) && ctype_digit($gruppeRoh) ? $gruppeRoh : 'ungueltiger Wert')
            );
            $this->zurueck('gruppe-unzulaessig');
        }

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
        //
        // M5: "unveraendert" gilt aber nur bei GLEICHER Adresse. Bisher ging
        // der gespeicherte Schluessel an jede neu eingetragene Adresse - wer
        // die Adresse aendern durfte, konnte ihn sich an einen eigenen
        // Server schicken lassen. Ob ein Schluessel da ist, entscheidet der
        // Rohwert (schluesselGespeichert()), nicht apiKey(): Das liefert bei
        // fremder Bindung '' und sagte sonst "kein Schluessel, Adresse frei".
        $schluesselRoh = is_string($_POST['api_key'] ?? null) ? trim($_POST['api_key']) : '';
        $schluessel = null;
        $bindung = null;
        if ($basis === '') {
            // Zugang entfernen: ohne Adresse kein Schluessel.
            $schluessel = '';
            $bindung = '';
        } elseif ($schluesselRoh !== '') {
            $schluessel = Crypto::encrypt($schluesselRoh);
            $bindung = Konfiguration::bindung($basis);
        } elseif (Konfiguration::schluesselGespeichert()) {
            $gespeichert = Konfiguration::gespeicherteBindung();
            $referenz = $gespeichert !== '' ? $gespeichert : Konfiguration::bindung(Konfiguration::basis());
            if (!hash_equals($referenz, Konfiguration::bindung($basis))) {
                // Nichts speichern - auch nicht Team, Gruppe oder Typen.
                PluginAudit::log(
                    Plugin::SLUG,
                    'Adressaenderung ohne neuen Schluessel abgelehnt',
                    'Mitglieder-Konten',
                    'Neue Basis: ' . $basis
                );
                $this->zurueck('schluessel-noetig');
            }
            if ($gespeichert === '') {
                $bindung = Konfiguration::bindung($basis); // Altbestand jetzt binden
            }
        }

        Konfiguration::speichern([
            Konfiguration::S_URL => $basis,
            Konfiguration::S_KEY => $schluessel,
            Konfiguration::S_KEY_BINDUNG => $bindung,
            Konfiguration::S_TEAM => $team,
            Konfiguration::S_GRUPPE => (string)$gruppe,
            Konfiguration::S_TYPEN => trim(is_string($_POST['typen'] ?? null) ? $_POST['typen'] : ''),
        ]);

        // Der Schluessel selbst steht NICHT im Protokoll - nur, dass er
        // gesetzt wurde.
        PluginAudit::log(
            Plugin::SLUG,
            'CiviCRM-Zugang gespeichert',
            'Mitglieder-Konten',
            sprintf(
                'Basis: %s, Schluessel %s, Zielgruppe %d',
                $basis === '' ? '(leer)' : $basis,
                $schluessel === null ? 'unveraendert' : ($schluessel === '' ? 'entfernt' : 'neu gesetzt'),
                $gruppe
            )
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
                '%d angelegt, %d uebernommen, %d uebersprungen, %d Fehler',
                $ergebnis['angelegt'],
                $ergebnis['reaktiviert'],
                $ergebnis['uebersprungen'],
                count($ergebnis['fehler'])
            )
        );

        $_SESSION['mitglieder_konten_bericht'] = [
            'angelegt' => $ergebnis['angelegt'],
            'reaktiviert' => $ergebnis['reaktiviert'],
            'uebersprungen' => $ergebnis['uebersprungen'],
            'fehler' => $ergebnis['fehler'],
        ];

        $this->zurueck('angelegt');
    }

    /**
     * Eine vom Tageslauf angehaltene Massenaenderung ausfuehren (N30).
     *
     * NUR ADMIN: Mit `manage` allein waere die Grenze nur ein zusaetzlicher
     * Klick fuer denselben, der sie ausgeloest hat. Und nur fuer die
     * ANGEZEIGTE Menge: Das Formular traegt ihren Fingerabdruck; hat ein
     * spaeterer Lauf sie ersetzt, gilt die Bestaetigung nicht.
     */
    public function sperrenBestaetigen(): void {
        $this->requireAdmin();
        $this->pruefeCsrf();

        $aktuell = Konfiguration::angehaltenFingerabdruck();
        if ($aktuell === '') {
            $this->zurueck('nichts-angehalten');
        }
        $gesehen = is_string($_POST['angehalten'] ?? null) ? $_POST['angehalten'] : '';
        if (!hash_equals($aktuell, $gesehen)) {
            $this->zurueck('angehalten-veraltet');
        }

        $bericht = Abgleich::taeglicherLauf(null, true);

        PluginAudit::log(
            Plugin::SLUG,
            'Angehaltenen Mitglieder-Abgleich bestaetigt',
            'Mitglieder-Konten',
            sprintf(
                '%d gesperrt, %d reaktiviert, %d unklar%s',
                $bericht['gesperrt'],
                $bericht['reaktiviert'],
                $bericht['unklar'],
                $bericht['angehalten'] ? ', weitere Aenderungen erneut angehalten' : ''
            )
        );

        if ($bericht['geprueft'] === 0) {
            $this->zurueck('bestaetigung-fehlgeschlagen');
        }
        $_SESSION['mitglieder_konten_lauf'] = [
            'gesperrt' => $bericht['gesperrt'],
            'reaktiviert' => $bericht['reaktiviert'],
        ];
        $this->zurueck($bericht['angehalten'] ? 'erneut-angehalten' : 'sperren-bestaetigt');
    }

    // ---- Anzeige -------------------------------------------------------

    private function meldung(): string {
        $marker = is_string($_GET['mk'] ?? null) ? $_GET['mk'] : '';
        $texte = [
            'gespeichert' => ['ok', 'Zugang gespeichert.'],
            'url-ungueltig' => ['fehler', 'Die Adresse muss mit https:// beginnen, darf keine Parameter und keine Zugangsdaten enthalten und nicht auf ein internes Netz zeigen.'],
            'schluessel-noetig' => ['fehler', 'Wird die Basis-Adresse geändert, muss der API-Schlüssel neu eingegeben werden. Es wurde nichts gespeichert.'],
            'team-ungueltig' => ['fehler', 'Die Adresse des Verwaltungsteams ist keine gültige E-Mail-Adresse.'],
            'nichts-ausgewaehlt' => ['fehler', 'Es war nichts ausgewählt.'],
            'gruppe-unzulaessig' => ['fehler', 'Als Gruppe für neue Konten ist nur eine reine Lesegruppe zulässig – nicht „Administrator“ und keine Gruppe mit Bearbeitungs- oder Veröffentlichungsrechten. Es wurde nichts gespeichert.'],
            'sperren-bestaetigt' => ['ok', 'Die angehaltenen Änderungen wurden ausgeführt.'],
            'erneut-angehalten' => ['fehler', 'Ausgeführt wurde nur die bestätigte Menge. CiviCRM meldet inzwischen weitere Änderungen über der Grenze – sie sind erneut angehalten.'],
            'angehalten-veraltet' => ['fehler', 'Die angehaltenen Änderungen haben sich seit dem Anzeigen geändert. Bitte die aktuelle Anzeige prüfen und erneut bestätigen. Es wurde nichts geändert.'],
            'nichts-angehalten' => ['fehler', 'Es ist keine Änderung angehalten.'],
            'bestaetigung-fehlgeschlagen' => ['fehler', 'CiviCRM konnte nicht befragt werden – es wurde nichts geändert.'],
        ];

        $html = '';

        if (isset($texte[$marker])) {
            [$art, $text] = $texte[$marker];
            $html .= $this->kasten($art, htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
        }

        $lauf = $_SESSION['mitglieder_konten_lauf'] ?? null;
        unset($_SESSION['mitglieder_konten_lauf']);
        if (is_array($lauf)) {
            $html .= $this->kasten('ok', sprintf(
                '%d Konto/Konten gesperrt, %d entsperrt.',
                (int)($lauf['gesperrt'] ?? 0),
                (int)($lauf['reaktiviert'] ?? 0)
            ));
        }

        $bericht = $_SESSION['mitglieder_konten_bericht'] ?? null;
        unset($_SESSION['mitglieder_konten_bericht']);
        if (is_array($bericht)) {
            $zeilen = sprintf(
                '%d Konto/Konten angelegt, %d übernommen, %d übersprungen.',
                (int)($bericht['angelegt'] ?? 0),
                (int)($bericht['reaktiviert'] ?? 0),
                (int)($bericht['uebersprungen'] ?? 0)
            );
            foreach ((array)($bericht['fehler'] ?? []) as $fehler) {
                $zeilen .= '<br>' . htmlspecialchars((string)$fehler, ENT_QUOTES, 'UTF-8');
            }
            $html .= $this->kasten(empty($bericht['fehler']) ? 'ok' : 'fehler', $zeilen);
        }

        return $html;
    }

    private function kasten(string $art, string $inhaltHtml): string {
        $farbe = match ($art) {
            'ok' => 'success',
            'warnung' => 'warning',
            default => 'danger',
        };

        return "<div class='card' style='background-color: var(--{$farbe}-soft-bg); color: var(--{$farbe}-fg);'>{$inhaltHtml}</div>";
    }

    /** Warnkasten fuer eine angehaltene Massenaenderung (N30). */
    private function angehaltenKarte(): string {
        $angehalten = Konfiguration::angehalten();
        if ($angehalten === []) {
            return '';
        }

        $zeilen = '';
        foreach ($angehalten as $richtung => $eintrag) {
            $gezeigt = array_slice($eintrag['ids'], 0, 50);
            $zeilen .= sprintf(
                '<li><strong>%d %s</strong> (Grenze %d, angehalten %s)<br><small>Mitgliedschaften: %s%s</small></li>',
                $eintrag['anzahl'],
                $richtung === 'sperren' ? 'Sperren' : 'Entsperrungen',
                $eintrag['grenze'],
                htmlspecialchars($eintrag['zeit'], ENT_QUOTES, 'UTF-8'),
                htmlspecialchars(implode(', ', $gezeigt), ENT_QUOTES, 'UTF-8'),
                count($eintrag['ids']) > count($gezeigt) ? ' …' : ''
            );
        }

        $inhalt = '<strong>Mitglieder-Abgleich angehalten.</strong> Der tägliche Lauf hätte mehr Konten auf einmal '
                . 'geändert, als plausibel ist. Es wurde nichts geändert.<ul>' . $zeilen . '</ul>';

        if ($this->isAdmin()) {
            $csrf = htmlspecialchars(Router::generateCsrfToken(), ENT_QUOTES, 'UTF-8');
            $abdruck = htmlspecialchars(Konfiguration::angehaltenFingerabdruck(), ENT_QUOTES, 'UTF-8');
            $inhalt .= "<form method='POST' action='" . Plugin::VERWALTUNG . "/sperren-bestaetigen'
                    data-confirm='Genau diese Änderungen jetzt ausführen?'>
                    <input type=\"hidden\" name=\"csrf_token\" value=\"{$csrf}\">
                    <input type=\"hidden\" name=\"angehalten\" value=\"{$abdruck}\">
                    <button type='submit' class='btn'>Diese Änderungen bestätigen</button>
                </form>";
        } else {
            $inhalt .= '<p>Bestätigen kann das nur ein Administrator.</p>';
        }

        return $this->kasten('warnung', $inhalt);
    }

    private function zugangKarte(): string {
        $istAdmin = $this->isAdmin();
        $csrf = htmlspecialchars(Router::generateCsrfToken(), ENT_QUOTES, 'UTF-8');
        $basis = htmlspecialchars(Konfiguration::basis(), ENT_QUOTES, 'UTF-8');
        $team = htmlspecialchars(Konfiguration::teamAdresse(), ENT_QUOTES, 'UTF-8');
        $typen = htmlspecialchars(implode(', ', Konfiguration::typIds()), ENT_QUOTES, 'UTF-8');

        // Nach dem Rohwert, nicht nach apiKey() (M5) - und mit dem Hinweis,
        // wenn der Schluessel zu einer anderen Adresse gehoert.
        $platzhalter = 'noch nicht gesetzt';
        if (Konfiguration::schluesselGespeichert()) {
            $platzhalter = Konfiguration::apiKey() !== ''
                ? 'gesetzt — leer lassen, um ihn zu behalten'
                : 'gesetzt, passt aber nicht zur Adresse — bitte neu eingeben';
        }

        $db = Database::getInstance();
        $gespeichert = Konfiguration::gruppeId();
        $zulaessig = Zielgruppe::zulaessige($db);

        // Altbestand: Eine frueher gespeicherte, jetzt unzulaessige Gruppe
        // darf nicht still durch "— keine —" ersetzt werden. Der Platzhalter
        // plus `required` zwingt zu einer bewussten Wahl.
        $altbestand = $gespeichert !== 0 && !in_array($gespeichert, array_column($zulaessig, 'id'), true);
        $optionen = $altbestand
            ? '<option value="" selected>— gespeicherte Gruppe ist nicht zulässig, bitte eine Lesegruppe wählen —</option>'
            : '';
        $optionen .= '<option value="0">— keine —</option>';
        foreach ($zulaessig as $gruppe) {
            $gewaehlt = $gruppe['id'] === $gespeichert ? ' selected' : '';
            $optionen .= sprintf(
                '<option value="%d"%s>%s</option>',
                $gruppe['id'],
                $gewaehlt,
                htmlspecialchars($gruppe['name'], ENT_QUOTES, 'UTF-8')
            );
        }
        $pflicht = $altbestand ? ' required' : '';

        // Nicht-Admins sehen die Einstellungen, koennen sie aber nicht
        // aendern - der Server prueft das in zugang() ohnehin.
        $gesperrt = $istAdmin ? '' : ' disabled';
        $hinweis = $istAdmin
            ? ''
            : "<p style='color:var(--text-muted);'><strong>Nur Administratoren können den Zugang ändern.</strong></p>";
        $knopf = $istAdmin ? "<button type='submit' class='btn' style='margin-top:1rem;'>Speichern</button>" : '';
        $schluesselFeld = $istAdmin
            ? "<label for='api_key' style='display:block;font-weight:bold;margin-top:0.8rem;'>API-Schlüssel</label>
                <input type='password' id='api_key' name='api_key' autocomplete='off' placeholder='{$platzhalter}' style='width:100%;padding:0.5rem;'>
                <small style='color:var(--text-muted);'>Wird verschlüsselt gespeichert und nie wieder angezeigt. Wird die Basis-Adresse geändert, muss er neu eingegeben werden.</small>"
            : '';

        return "<div class='card'>
            <h2 style='font-size:1.15rem;margin-top:0;'>CiviCRM-Zugang</h2>
            <p style='color:var(--text-muted);'>
                Gelesen werden ausschliesslich laufende Mitgliedschaften und der zugehörige Kontakt
                (Name, Adresse). Es wird nichts übernommen und nichts zurückgeschrieben.
            </p>
            {$hinweis}
            <form method='POST' action='" . Plugin::VERWALTUNG . "/zugang'>
                <fieldset style='border:0;padding:0;margin:0;'{$gesperrt}>
                <input type=\"hidden\" name=\"csrf_token\" value=\"{$csrf}\">
                <label for='basis_url' style='display:block;font-weight:bold;'>Basis-Adresse</label>
                <input type='url' id='basis_url' name='basis_url' value='{$basis}' placeholder='https://civicrm.example.org' style='width:100%;padding:0.5rem;'>

                {$schluesselFeld}

                <label for='gruppe' style='display:block;font-weight:bold;margin-top:0.8rem;'>Gruppe für neue Konten</label>
                <select id='gruppe' name='gruppe' style='width:100%;padding:0.5rem;'{$pflicht}>{$optionen}</select>
                <small style='color:var(--text-muted);'>
                    Zur Auswahl stehen nur reine <em>Lese</em>-Gruppen. Administratoren und Gruppen mit
                    Bearbeitungs- oder Veröffentlichungsrechten sind ausgeschlossen (Framework#348).
                </small>

                <label for='team_email' style='display:block;font-weight:bold;margin-top:0.8rem;'>Adresse des Verwaltungsteams</label>
                <input type='email' id='team_email' name='team_email' value='{$team}' style='width:100%;padding:0.5rem;'>
                <small style='color:var(--text-muted);'>Dorthin gehen die Zugangsdaten für Mitglieder ohne eigene Adresse.</small>

                <label for='typen' style='display:block;font-weight:bold;margin-top:0.8rem;'>Mitgliedschaftsarten (IDs, leer = alle)</label>
                <input type='text' id='typen' name='typen' value='{$typen}' placeholder='z. B. 1, 3' style='width:100%;padding:0.5rem;'>
                <small style='color:var(--text-muted);'>Gilt nur für die Anlage neuer Konten, nicht für die Sperre.</small>

                {$knopf}
                </fieldset>
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

        $zaehler = ['neu' => 0, 'wiedereintritt' => 0, 'vorhanden' => 0, 'blockiert' => 0];
        $tabelle = '';
        $gezeigt = 0;

        foreach ($zeilen as $zeile) {
            $zaehler[$zeile['zustand']] = ($zaehler[$zeile['zustand']] ?? 0) + 1;

            // Nur die anlegbaren bekommen eine Zeile mit Kaestchen. Der Rest
            // steht in der Zusammenfassung - eine Liste mit 1.400 bereits
            // vorhandenen Konten hilft niemandem.
            if ($zeile['zustand'] === 'vorhanden' || $gezeigt >= Abgleich::MAX_JE_STAPEL * 3) {
                continue;
            }
            $gezeigt++;

            $anlegbar = in_array($zeile['zustand'], ['neu', 'wiedereintritt'], true);
            $kaestchen = $anlegbar
                ? sprintf("<input type='checkbox' name='membership_ids[]' value='%d' checked>", (int)$zeile['membership_id'])
                : '—';
            if ($zeile['zustand'] === 'neu') {
                $grund = $zeile['email'] === '' ? '<em>ohne eigene Adresse — geht ans Verwaltungsteam</em>' : '';
            } else {
                $grund = htmlspecialchars((string)$zeile['grund'], ENT_QUOTES, 'UTF-8');
            }

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
                <strong>{$zaehler['wiedereintritt']}</strong> werden übernommen &middot;
                <strong>{$zaehler['vorhanden']}</strong> haben schon ein Konto &middot;
                <strong>{$zaehler['blockiert']}</strong> gehen nicht.
                Je Durchgang werden höchstens <strong>{$deckel}</strong> Konten angelegt.
            </p>
            <form method='POST' action='" . Plugin::VERWALTUNG . "/anlegen'
                  data-confirm='Ausgewählte Konten jetzt anlegen bzw. übernehmen? Zugangsdaten neuer Konten gehen unmittelbar heraus.'>
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
