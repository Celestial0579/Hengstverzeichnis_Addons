<?php
// mitgliedsstatus/Plugin.php
//
// Addon für Hengstverzeichnis_Framework, löst Addons#132 (und den darin
// festgelegten Zuschnitt A aus dem Bericht zu Addons#130).
//
// Es tut zwei Dinge, die nur auf den ersten Blick nichts miteinander zu tun
// haben - der Bericht zu #130 hat sie ausdrücklich in EIN Addon gelegt, statt
// für ein einzelnes Kennungsfeld ein zweites anzulegen:
//
//  1. MITGLIEDSSTATUS je Kontakt, eigenständig gepflegt, aus einer FESTEN
//     Werteliste (Mitglied / Nichtmitglied / keine Angabe).
//  2. CIVICRM-VERLINKUNG je Kontakt: eine Kennung, eine Basis-URL in den
//     Addon-Einstellungen, ein Link. Mehr nicht.
//
// DIE VIER ENTSCHEIDUNGEN, DIE MAN DEM CODE SONST NICHT ANSIEHT:
//
//  1. FESTE LISTE STATT FREITEXT. Der Kern führt `contacts.membership_status`
//     bis v0.8 als freies Textfeld ("z. B. Mitglied / Nichtmitglied NO") und
//     entfernt die Spalte in v0.9.0 (Framework#349). Freitext ist nicht
//     auswertbar: 'Mitglied', 'mitglied', 'Vollmitglied' und 'Nichtmitglied
//     NO' stehen nebeneinander und meinen teils dasselbe, teils etwas
//     anderes. Hier wird daraus ein Wert aus einer abgeschlossenen Liste.
//
//  2. DIE ÜBERNAHME IST EINE ÜBERGABE, KEIN ABGLEICH. Sie läuft genau einmal,
//     bei der Installation, und ist durch einen Marker geschützt - siehe den
//     ausführlichen Kommentar an Uebernahme. Was sich nicht ohne Raten
//     abbilden lässt, wird NICHT verworfen: Der Wortlaut bleibt Zeichen für
//     Zeichen erhalten (`altwert`), die Zeile wird als offen markiert, und
//     die Verwaltungsseite fragt einen Menschen, was damit geschehen soll.
//     Eine Maschine, die 'Nichtmitglied NO' selbständig zu 'Nichtmitglied'
//     macht, hat das 'NO' geraten - es ist in diesem Bestand ein Länderkürzel
//     (siehe database/schema.sql im Kern, Kommentar an `country`).
//
//  3. NICHT ÖFFENTLICH, SOLANGE ES NIEMAND FREISCHALTET. Heute ist der Wert
//     bedingungslos öffentlich. "X ist kein Mitglied" ist eine Aussage über
//     einen Menschen; sie gehört nicht ungefragt auf eine öffentliche Seite.
//     Die Sichtbarkeit ist deshalb je Kontakt schaltbar (Vorgabe: aus) UND
//     hängt zusätzlich am Recht `mitgliedsstatus.view` der Gast-Gruppe.
//     Beides muss zutreffen - fail-closed, wie `contact_public` im Kern.
//
//  4. KEIN DATENABGLEICH MIT CIVICRM. Gespeichert wird eine Kennung, gebaut
//     wird ein Link. Es wird nichts abgefragt, nichts übernommen, nichts
//     zurückgeschrieben, und es wird nie über Namensähnlichkeit geraten, wer
//     zu wem gehört (Addons#130, Zuschnitte B und D sind abgewählt; #131
//     sagt "kein Datenabgleich"). Zwei Betriebe mit ähnlichem Namen sind
//     nicht derselbe, und ein falsch verknüpfter Mitgliedsdatensatz fällt
//     niemandem auf.
//
// WAS DIESES ADDON NICHT KANN, UND WARUM: Die Angabe stand bis v0.8 an zwei
// Stellen - auf der Kontaktseite und inline im Personenblock der Pferdeseite
// (`public_horse_detail.php`). Die zweite Stelle ist für ein Addon nicht
// erreichbar: `horse.detail_sections` hängt Abschnitte hinten an, innerhalb
// der Personenzeile gibt es keinen Erweiterungspunkt. Die Angabe erscheint
// dort künftig nicht mehr. Das ist in Addons#132 als bewusste Entscheidung
// festgehalten, nicht als Versäumnis.
//
// Installation (lokal im Framework-Repo):
//   cp -r mitgliedsstatus plugins/mitgliedsstatus
// Danach unter Admin -> Plugins verwalten (/admin/plugins) aktivieren.

namespace Plugin\Mitgliedsstatus;

use App\Controllers\BaseController;
use App\Database;
use App\Permission\GroupMembership;
use App\Plugin\HookManager;
use App\Plugin\PluginAudit;
use App\Plugin\PluginPage;
use App\Router;
use PDO;

class Plugin {

    /** Der eigene Slug - Kategorie jedes Protokolleintrags (Framework#352). */
    public const SLUG = 'mitgliedsstatus';

    /** Eigenes Rechte-Modul: `view` schaltet die öffentliche Anzeige frei, `manage` die Verwaltung. */
    public const MODUL = 'mitgliedsstatus';

    /** Adresse der Verwaltungsseite - an einer Stelle, damit Kachel und Umleitungen nicht driften. */
    public const VERWALTUNG = '/plugin/mitgliedsstatus/verwaltung';

    public function register(HookManager $hooks): void {
        // Ausschliesslich die contact.*-Hooks. Der Kern löst person.* und
        // station.* bis v0.9.0 zusätzlich als KASKADIERENDEN Alias aus, jeweils
        // auf dem Ergebnis des vorherigen (siehe docs/plugin-development.md).
        // Seit persons und breeding_stations EINE Tabelle sind (Framework#336),
        // bekäme ein Addon, das beide Paare registriert, denselben Datensatz
        // mehrfach - der Abschnitt stünde zwei- oder dreimal auf derselben
        // Seite. Wer hier einen Alias "sicherheitshalber" ergänzt, baut genau
        // diese Doppelung.
        $hooks->addFilter('contact.detail_sections', [$this, 'oeffentlicherAbschnitt']);
        $hooks->addFilter('contact.edit_sections', [$this, 'pflegeAbschnitte']);
        $hooks->addFilter('admin.dashboard_tiles', [$this, 'dashboardKachel']);
    }

    /**
     * Framework#75: Der PluginManager ruft install() bei JEDER Aktivierung und
     * nach jedem Addon-Update auf - deshalb idempotent, und deshalb steht das
     * DDL hier und nicht in register().
     *
     * Genau diese Wiederholung ist der Grund, warum die Übernahme der
     * Bestandswerte einen Marker braucht: Sie ist für sich NICHT wiederholbar,
     * siehe den Klassenkommentar an Uebernahme.
     */
    public function install(): void {
        $db = Database::getInstance();

        // Fremdschlüssel MIT CASCADE, anders als beim Addon `kontaktanfrage`:
        // Dort ist eine Anfrage ein Vorgang, der den Datensatz überleben soll.
        // Hier ist die Zeile eine AUSSAGE ÜBER einen Kontakt und ohne ihn
        // sinnlos - und sie ist personenbezogen. Wird der Kontakt endgültig
        // gelöscht (DSGVO), muss sie mitgehen, sonst bliebe "Kontakt 42 ist
        // kein Mitglied" in einer Nebentabelle liegen, während der Kern die
        // Löschung für vollständig hält.
        //
        // `altwert` ist bewusst genauso breit wie `contacts.membership_status`
        // (VARCHAR(100)): Nur dann ist die Sicherung des Wortlauts Zeichen für
        // Zeichen vollständig und der Rückweg ohne Verlust möglich.
        $db->exec(
            'CREATE TABLE IF NOT EXISTS `' . Status::TABELLE . '` (
                `contact_id` INT NOT NULL PRIMARY KEY,
                `status` VARCHAR(16) NOT NULL DEFAULT \'' . Werte::KEINE_ANGABE . '\',
                `oeffentlich` TINYINT(1) NOT NULL DEFAULT 0,
                `altwert` VARCHAR(100) NULL DEFAULT NULL,
                `offen` TINYINT(1) NOT NULL DEFAULT 0,
                `geaendert_von` VARCHAR(100) NULL DEFAULT NULL,
                `geaendert_am` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_ms_offen` (`offen`),
                CONSTRAINT `fk_ms_kontakt` FOREIGN KEY (`contact_id`)
                    REFERENCES `contacts` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        // Eigene Tabelle statt einer Spalte in der obigen: Die Zuordnung
        // Kontakt -> CiviCRM-Kontakt ist eine andere Sache als der
        // Mitgliedsstatus, sie hat ein anderes Publikum (sie ist NIE
        // öffentlich) und Addons#132 hält ausdrücklich fest, dass es diese
        // Zuordnung ein zweites Mal geben wird - für die Kontenanlage aus
        // Addons#131. Eine eigene, so benannte Tabelle kann das andere Addon
        // finden und mitbenutzen; eine Spalte in einer Statustabelle nicht.
        $db->exec(
            'CREATE TABLE IF NOT EXISTS `' . Verknuepfung::TABELLE . '` (
                `contact_id` INT NOT NULL PRIMARY KEY,
                `civicrm_contact_id` INT UNSIGNED NOT NULL,
                `geaendert_von` VARCHAR(100) NULL DEFAULT NULL,
                `geaendert_am` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_ms_civicrm` (`civicrm_contact_id`),
                CONSTRAINT `fk_ms_civicrm_kontakt` FOREIGN KEY (`contact_id`)
                    REFERENCES `contacts` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        // Erst NACH dem DDL - die Übernahme schreibt in die Tabelle oben.
        Uebernahme::einmalig($db);
    }

    /**
     * Framework#338: Was sich nicht im Manifest aufzählen lässt. Die beiden
     * eigenen Tabellen und die beiden Einstellungen stehen unter "owns" in der
     * plugin.json - hier bleibt nur, was dieses Addon in KERN-Tabellen
     * hinterlassen hat.
     *
     * Die Protokolleinträge unter der Kategorie `mitgliedsstatus` werden
     * ausdrücklich NICHT gelöscht: Sie sind der Nachweis darüber, wer wann
     * welchen Kontakt als Nichtmitglied geführt und wer eine Angabe öffentlich
     * geschaltet hat. Ein Nachweis, den das Deinstallieren mitnimmt, ist
     * keiner.
     *
     * `contacts.membership_status` wird hier NICHT zurückgeschrieben. Das wäre
     * naheliegend und wäre falsch: Der Rückweg ist eine Entscheidung des
     * Betreibers, er steht als eigener Knopf auf der Verwaltungsseite, und er
     * gehört VOR das Deinstallieren - danach ist die Sicherung (`altwert`)
     * mit der Tabelle weg.
     */
    public function uninstall(): void {
        PluginAudit::log(
            self::SLUG,
            'Addon deinstalliert',
            'Mitgliedsstatus',
            'Statustabelle, CiviCRM-Zuordnung und Einstellungen werden entfernt.'
        );
    }

    /**
     * @param array<int, string> $sections
     * @param array<string, mixed> $kontakt
     * @param array<string, mixed> $horsesByRole
     * @param array<string, mixed> $stationHorses
     * @return array<int, string>
     */
    public function oeffentlicherAbschnitt(
        array $sections,
        array $kontakt,
        array $horsesByRole = [],
        array $stationHorses = []
    ): array {
        $html = Abschnitte::oeffentlich($kontakt);
        if ($html !== '') {
            $sections[] = $html;
        }
        return $sections;
    }

    /**
     * Zwei Abschnitte aus EINEM Callback: Mitgliedsstatus und
     * CiviCRM-Verlinkung sind verschiedene Angaben mit verschiedenen
     * Zielsystemen, sie bekommen getrennte Formulare, getrennte Routen und
     * getrennte Protokolleinträge. Ein gemeinsames Formular würde beim
     * Speichern des einen das andere mitschreiben.
     *
     * @param array<int, string> $sections
     * @param array<string, mixed> $kontakt
     * @return array<int, string>
     */
    public function pflegeAbschnitte(array $sections, array $kontakt): array {
        $id = isset($kontakt['id']) ? (int) $kontakt['id'] : 0;
        if ($id < 1) {
            return $sections;
        }

        // Fail-closed: Wer den Kontakt nicht bearbeiten darf, sieht die
        // Abschnitte gar nicht erst. Der Kern schützt das Formular selbst
        // bereits mit `contacts.edit`; die Prüfung hier steht trotzdem, weil
        // ein Abschnitt, der seine eigene Bedingung nicht kennt, beim nächsten
        // Umbau des aufrufenden Controllers stillschweigend zu weit sichtbar
        // wird.
        if (!GroupMembership::hasPermission($_SESSION['user_id'] ?? null, 'contacts', 'edit')) {
            return $sections;
        }

        $sections[] = Abschnitte::statusPflege($id);
        $sections[] = Abschnitte::civicrmPflege($id);
        return $sections;
    }

    /**
     * @param array<int, array{url:string,label:string,icon:string}> $tiles
     * @return array<int, array{url:string,label:string,icon:string}>
     */
    public function dashboardKachel(array $tiles): array {
        $tiles[] = [
            'url' => self::VERWALTUNG,
            'label' => 'Mitgliedsstatus',
            'icon' => '🎗',
        ];
        return $tiles;
    }

    /**
     * `view` steht bewusst zuerst: Jedes Modul bekommt die Standard-Aktionen
     * `view` und `publish` automatisch (PermissionRegistry::STANDARD_ACTIONS),
     * eine eigene Beschriftung greift nur, wer sie zuerst registriert. "Sehen"
     * heisst hier etwas sehr Bestimmtes - nämlich, dass Gäste die Angabe auf
     * der öffentlichen Kontaktseite lesen dürfen -, und das soll in der Matrix
     * unter /admin/groups auch so dastehen.
     *
     * @return array<int, array{module:string, action:string, label:string, module_label:string}>
     */
    public function permissions(): array {
        return [
            [
                'module' => self::MODUL,
                'action' => 'view',
                'label' => 'Freigegebene Angabe auf der öffentlichen Kontaktseite sehen',
                'module_label' => 'Mitgliedsstatus',
            ],
            [
                'module' => self::MODUL,
                'action' => 'manage',
                'label' => 'Übernahme nacharbeiten und CiviCRM-Einstellungen pflegen',
                'module_label' => 'Mitgliedsstatus',
            ],
        ];
    }

    /**
     * @return array<int, array{method:string, path:string, callback:array}>
     */
    public function routes(): array {
        return [
            ['method' => 'POST', 'path' => '/kontakt/status', 'callback' => [KontaktController::class, 'status']],
            ['method' => 'POST', 'path' => '/kontakt/civicrm', 'callback' => [KontaktController::class, 'civicrm']],
            ['method' => 'GET', 'path' => '/verwaltung', 'callback' => [VerwaltungController::class, 'index']],
            ['method' => 'POST', 'path' => '/verwaltung/zuordnen', 'callback' => [VerwaltungController::class, 'zuordnen']],
            ['method' => 'POST', 'path' => '/verwaltung/civicrm-url', 'callback' => [VerwaltungController::class, 'civicrmUrl']],
            ['method' => 'POST', 'path' => '/verwaltung/kern-freitext', 'callback' => [VerwaltungController::class, 'kernFreitext']],
        ];
    }
}

/**
 * Die abgeschlossene Werteliste und die Abbildung von Freitext darauf.
 *
 * Reine Funktionen ohne Datenbank - genau deshalb steht die Abbildungsregel
 * hier und nicht in der Übernahme: Sie ist die Stelle, an der ein Fehler
 * lautlos ganze Bestände falsch einsortieren würde, und sie ist als
 * Unit-Test festnagelbar (tests/Unit/MitgliedsstatusWerteTest.php).
 *
 * DIE REGEL LAUTET: exakte Übereinstimmung nach Normalisierung, sonst nichts.
 * Normalisiert wird nur, was keine Bedeutung trägt - Rand-Leerraum, mehrfacher
 * Leerraum, Gross-/Kleinschreibung. Die Synonymliste enthält ausschliesslich
 * Schreibweisen DESSELBEN Wortes, keine Schlüsse: 'nicht-mitglied' ist
 * 'Nichtmitglied', 'Nichtmitglied NO' ist es NICHT - das 'NO' ist in diesem
 * Bestand ein Länderkürzel (siehe database/schema.sql im Kern), und wer es
 * wegwirft, behauptet, es hätte nichts bedeutet.
 */
final class Werte {

    public const MITGLIED = 'mitglied';
    public const NICHTMITGLIED = 'nichtmitglied';
    public const KEINE_ANGABE = 'keine_angabe';

    /**
     * Schreibweisen desselben Wortes. Erweitern ist erlaubt und erwartet -
     * aber nur um Schreibweisen, nie um Wortlaute, die zusätzlich etwas
     * anderes aussagen.
     *
     * @var array<string, array<int, string>>
     */
    private const SYNONYME = [
        self::MITGLIED => [
            'mitglied', 'mitglieder', 'vollmitglied', 'ordentliches mitglied', 'member', 'members',
        ],
        self::NICHTMITGLIED => [
            'nichtmitglied', 'nichtmitglieder', 'nicht mitglied', 'nicht-mitglied',
            'kein mitglied', 'non-member', 'nonmember', 'non member', 'no member',
        ],
    ];

    private function __construct() {}

    /** @return array<string, string> Wert => Anzeigetext */
    public static function alle(): array {
        return [
            self::MITGLIED => 'Mitglied',
            self::NICHTMITGLIED => 'Nichtmitglied',
            self::KEINE_ANGABE => 'keine Angabe',
        ];
    }

    public static function istGueltig(string $wert): bool {
        return array_key_exists($wert, self::alle());
    }

    public static function label(string $wert): string {
        return self::alle()[$wert] ?? self::alle()[self::KEINE_ANGABE];
    }

    /** Aus einer Formulareingabe - alles Unbekannte wird zu "keine Angabe", nie zu einem Status. */
    public static function ausEingabe(mixed $roh): string {
        $wert = is_string($roh) ? $roh : '';
        return self::istGueltig($wert) ? $wert : self::KEINE_ANGABE;
    }

    /**
     * Nur Bedeutungsloses wird eingeebnet. mb_strtolower, damit 'MITGLIED'
     * und 'Mitglied' zusammenfallen - der Bestand ist über Jahre von
     * verschiedenen Händen gepflegt worden.
     */
    public static function normalisieren(string $roh): string {
        $text = trim($roh);
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        return mb_strtolower($text, 'UTF-8');
    }

    /**
     * Bildet einen Bestands-Freitext auf die Werteliste ab.
     *
     * @return string|null Der Wert, oder null wenn sich der Wortlaut nicht
     *                     ohne Raten abbilden lässt. null heisst ausdrücklich
     *                     "unklar", nicht "keine Angabe" - die Übernahme hält
     *                     den Wortlaut fest und legt ihn einem Menschen vor.
     */
    public static function ausFreitext(string $roh): ?string {
        $normal = self::normalisieren($roh);
        if ($normal === '') {
            return null;
        }

        foreach (self::SYNONYME as $wert => $schreibweisen) {
            if (in_array($normal, $schreibweisen, true)) {
                return $wert;
            }
        }

        return null;
    }
}

/**
 * Lesen und Schreiben des Status je Kontakt.
 *
 * Fehlt die Zeile, gilt "keine Angabe, nicht öffentlich" - das ist der sichere
 * Zustand und zugleich der Normalfall für jeden Kontakt, zu dem nie jemand
 * etwas gesagt hat.
 */
final class Status {

    public const TABELLE = 'plugin_mitgliedsstatus_kontakt';

    private function __construct() {}

    /**
     * @return array{status:string, oeffentlich:bool, altwert:string, offen:bool}
     */
    public static function fuerKontakt(int $kontaktId): array {
        $leer = ['status' => Werte::KEINE_ANGABE, 'oeffentlich' => false, 'altwert' => '', 'offen' => false];
        if ($kontaktId < 1) {
            return $leer;
        }

        try {
            $stmt = Database::getInstance()->prepare(
                'SELECT status, oeffentlich, altwert, offen FROM `' . self::TABELLE . '` WHERE contact_id = ?'
            );
            $stmt->execute([$kontaktId]);
            $zeile = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            // Tabelle fehlt (die Aktivierung läuft gerade, oder install() ist
            // gescheitert): fail-closed. Lieber keine Angabe als eine, die
            // niemand geprüft hat.
            return $leer;
        }

        if (!is_array($zeile)) {
            return $leer;
        }

        $wert = (string) ($zeile['status'] ?? '');
        return [
            'status' => Werte::istGueltig($wert) ? $wert : Werte::KEINE_ANGABE,
            'oeffentlich' => (int) ($zeile['oeffentlich'] ?? 0) === 1,
            'altwert' => (string) ($zeile['altwert'] ?? ''),
            'offen' => (int) ($zeile['offen'] ?? 0) === 1,
        ];
    }

    /**
     * Schreibt die redaktionelle Entscheidung. `altwert` wird bewusst NICHT
     * angefasst - er ist die Sicherung des Bestandswortlauts und bleibt als
     * Herkunftsnachweis stehen, auch nachdem ein Mensch entschieden hat.
     * `offen` fällt dabei auf 0: Die Frage ist beantwortet.
     */
    public static function setzen(int $kontaktId, string $status, bool $oeffentlich, string $benutzer): void {
        $stmt = Database::getInstance()->prepare(
            'INSERT INTO `' . self::TABELLE . '` (contact_id, status, oeffentlich, offen, geaendert_von)
             VALUES (:id, :status, :oeffentlich, 0, :benutzer)
             ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                oeffentlich = VALUES(oeffentlich),
                offen = 0,
                geaendert_von = VALUES(geaendert_von)'
        );
        $stmt->execute([
            'id' => $kontaktId,
            'status' => $status,
            'oeffentlich' => $oeffentlich ? 1 : 0,
            'benutzer' => $benutzer,
        ]);
    }
}

/**
 * Die Zuordnung Kontakt -> CiviCRM-Kontakt und der Link dorthin.
 *
 * Die Kennung ist NIE öffentlich. Sie ist eine Kennung eines Menschen in einem
 * fremden System; sie steht nur im Bearbeitungsformular, nur hinter
 * `contacts.edit`, und sie geht auch nicht ins Protokoll (siehe
 * KontaktController::civicrm()).
 */
final class Verknuepfung {

    public const TABELLE = 'plugin_mitgliedsstatus_civicrm';

    private function __construct() {}

    public static function fuerKontakt(int $kontaktId): ?int {
        if ($kontaktId < 1) {
            return null;
        }

        try {
            $stmt = Database::getInstance()->prepare(
                'SELECT civicrm_contact_id FROM `' . self::TABELLE . '` WHERE contact_id = ?'
            );
            $stmt->execute([$kontaktId]);
            $wert = $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return null;
        }

        return $wert === false ? null : (int) $wert;
    }

    /** $civicrmId === null entfernt die Zuordnung. */
    public static function setzen(int $kontaktId, ?int $civicrmId, string $benutzer): void {
        $db = Database::getInstance();

        if ($civicrmId === null) {
            $db->prepare('DELETE FROM `' . self::TABELLE . '` WHERE contact_id = ?')->execute([$kontaktId]);
            return;
        }

        $db->prepare(
            'INSERT INTO `' . self::TABELLE . '` (contact_id, civicrm_contact_id, geaendert_von)
             VALUES (:id, :civi, :benutzer)
             ON DUPLICATE KEY UPDATE
                civicrm_contact_id = VALUES(civicrm_contact_id),
                geaendert_von = VALUES(geaendert_von)'
        )->execute(['id' => $kontaktId, 'civi' => $civicrmId, 'benutzer' => $benutzer]);
    }

    /**
     * Kennung aus einer Formulareingabe.
     *
     * Leer heisst "entfernen" (null bei $leerErlaubt), alles andere muss eine
     * positive Ganzzahl SEIN - nicht "sich zu einer machen lassen". (int)"12x"
     * wäre 12; filter_var lehnt ab. Bei einer Kennung, die auf einen fremden
     * Menschen zeigt, ist das kein Feinschliff.
     *
     * @return array{0: bool, 1: ?int} [gültig, Kennung]
     */
    public static function kennungAusEingabe(mixed $roh): array {
        $text = trim(is_string($roh) ? $roh : '');
        if ($text === '') {
            return [true, null];
        }

        // Obergrenze ist INT UNSIGNED der eigenen Spalte - ein grösserer Wert
        // würde beim Schreiben stillschweigend gekappt und zeigte dann auf
        // einen anderen CiviCRM-Kontakt.
        $id = filter_var($text, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
        return is_int($id) ? [true, $id] : [false, null];
    }

    /** Der Link in die CiviCRM-Instanz, oder null wenn keine Basis-URL hinterlegt ist. */
    public static function link(int $civicrmId): ?string {
        $basis = Konfiguration::civicrmBasis();
        if ($basis === '' || $civicrmId < 1) {
            return null;
        }

        // Die von CiviCRM dokumentierte Adresse der Kontaktansicht.
        return $basis . '/civicrm/contact/view?reset=1&cid=' . $civicrmId;
    }
}

/**
 * Die beiden Einstellungen dieses Addons.
 *
 * WO SIE LIEGEN: in der Kern-Tabelle `settings`, unter Namen mit dem
 * Pflichtpräfix `plugin_` (Framework#338). Bewusst keine eigene
 * Konfigurationstabelle - es sind zwei Werte, und das Register `owns` kann
 * Einstellungen genauso aufzählen und beim Deinstallieren entfernen wie
 * Tabellen.
 */
final class Konfiguration {

    public const SCHLUESSEL_URL = 'plugin_mitgliedsstatus_civicrm_url';
    public const SCHLUESSEL_UEBERNAHME = 'plugin_mitgliedsstatus_uebernahme';

    /** @var array<string, string>|null Request-Cache */
    private static ?array $cache = null;

    private function __construct() {}

    /** @return array<string, string> */
    private static function alle(): array {
        if (self::$cache !== null) {
            return self::$cache;
        }

        try {
            $stmt = Database::getInstance()->prepare(
                'SELECT setting_key, setting_value FROM settings WHERE setting_key IN (?, ?)'
            );
            $stmt->execute([self::SCHLUESSEL_URL, self::SCHLUESSEL_UEBERNAHME]);
            $zeilen = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            self::$cache = is_array($zeilen) ? $zeilen : [];
        } catch (\Throwable $e) {
            // Ohne Einstellungen gilt "nicht eingerichtet" - der strengere Fall.
            self::$cache = [];
        }

        return self::$cache;
    }

    public static function civicrmBasis(): string {
        return trim((string) (self::alle()[self::SCHLUESSEL_URL] ?? ''));
    }

    /**
     * Prüft und normalisiert die Basis-URL.
     *
     * Nur http/https, und der Rest der Adresse muss leer sein: Ein Link, der
     * aus einer Basis mit eigener Query zusammengesetzt wird, führt sonst
     * woanders hin als gedacht. Abgelehnt werden damit auch `javascript:` und
     * `data:` - der Wert landet in einem href auf einer Admin-Seite, und dort
     * trifft ein Fehler Redakteure mit vollen Rechten.
     *
     * @return array{0: bool, 1: string} [gültig, normalisierte Basis ('' = keine)]
     */
    public static function basisPruefen(string $roh): array {
        $text = trim($roh);
        if ($text === '') {
            return [true, ''];
        }

        $teile = parse_url($text);
        if (!is_array($teile) || !isset($teile['scheme'], $teile['host'])) {
            return [false, ''];
        }
        if (!in_array(strtolower($teile['scheme']), ['http', 'https'], true)) {
            return [false, ''];
        }
        if (isset($teile['query']) || isset($teile['fragment']) || isset($teile['user']) || isset($teile['pass'])) {
            return [false, ''];
        }
        if (filter_var($text, FILTER_VALIDATE_URL) === false) {
            return [false, ''];
        }

        return [true, rtrim($text, '/')];
    }

    /** Der Bericht der Übernahme, oder null wenn sie noch nicht gelaufen ist. */
    public static function uebernahmeBericht(): ?array {
        $roh = (string) (self::alle()[self::SCHLUESSEL_UEBERNAHME] ?? '');
        if ($roh === '') {
            return null;
        }

        $daten = json_decode($roh, true);
        return is_array($daten) ? $daten : ['zeitpunkt' => '', 'gesamt' => 0, 'zugeordnet' => 0, 'offen' => 0];
    }

    public static function setzen(string $schluessel, string $wert): void {
        Database::getInstance()->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = ?'
        )->execute([$schluessel, $wert, $wert]);
        self::$cache = null;
    }

    public static function cacheVerwerfen(): void {
        self::$cache = null;
    }
}

/**
 * Die einmalige Übernahme der Bestandswerte aus `contacts.membership_status`
 * (Addons#132, Framework#349).
 *
 * WARUM SIE NICHT OPTIONAL IST. Der Kern entfernt die Spalte in v0.9.0. Läuft
 * die Übernahme nicht, fallen die gepflegten Werte zwischen die beiden
 * Releases - unwiederbringlich, weil nach dem Kern-Update nichts mehr da ist,
 * woraus man sie holen könnte. Addons#132 nennt die Reihenfolge deshalb
 * ausdrücklich: Addon steht und hat übernommen, DANN entfernt der Kern.
 *
 * WARUM EIN MARKER. install() läuft bei JEDER Aktivierung und nach jedem
 * Addon-Update erneut - der PluginManager garantiert "mindestens einmal", nicht
 * "genau einmal". Die Übernahme ist aber eine ÜBERGABE und kein Abgleich: Nach
 * dem ersten Lauf ist der gepflegte Bestand hier, und die Freitextspalte des
 * Kerns ist ein eingefrorener Altstand. Ein zweiter Lauf würde die Arbeit
 * eines Menschen ("dieser Wortlaut bedeutet Nichtmitglied") mit genau dem
 * Altstand überschreiben, der ihn zu dieser Arbeit gezwungen hat - und zwar
 * lautlos, denn eine Deaktivierung zur Fehlersuche und ein Wiedereinschalten
 * sieht niemand als Datenänderung an.
 *
 * Deshalb: Marker und Daten in DERSELBEN Transaktion. Ein Abbruch nimmt beides
 * zurück, ein Erfolg hält beides fest; es gibt keinen Zwischenstand, in dem
 * die Daten übernommen sind, der Marker aber fehlt. Kein DDL innerhalb der
 * Klammer - in MariaDB beendet jedes DDL die Transaktion implizit, die
 * Klammer wäre dann eine Illusion.
 *
 * "KONNTE NICHT" UND "WAR NICHTS ZU TUN" SIND VERSCHIEDENE AUSSAGEN. Fehlt die
 * Tabelle `contacts`, läuft ein Kern der 0.7-Linie und die Übernahme ist noch
 * nicht möglich - dann wird KEIN Marker gesetzt, damit der nächste Lauf es
 * erneut versucht. Fehlt dagegen die Spalte `membership_status` bei
 * vorhandener Tabelle, ist der Kern schon über v0.9.0 hinaus und es gibt
 * dauerhaft nichts zu übernehmen - dann gehört der Marker gesetzt, sonst
 * suchte später jemand nach einer Übernahme, die nie kommen kann.
 */
final class Uebernahme {

    private function __construct() {}

    public static function einmalig(PDO $db): void {
        if (Konfiguration::uebernahmeBericht() !== null) {
            return;
        }

        if (!self::tabelleExistiert($db, 'contacts')) {
            return;
        }

        if (!self::spalteExistiert($db, 'contacts', 'membership_status')) {
            self::abschliessen($db, ['gesamt' => 0, 'zugeordnet' => 0, 'offen' => 0, 'grund' => 'keine-spalte']);
            return;
        }

        // Auch Kontakte im Papierkorb (`deleted_at IS NOT NULL`) werden
        // übernommen. Sie sind wiederherstellbar; ihren Wert jetzt liegen zu
        // lassen hiesse, ihn beim Wiederherstellen verloren zu haben.
        $zeilen = $db->query(
            "SELECT id, membership_status FROM contacts
             WHERE membership_status IS NOT NULL AND TRIM(membership_status) <> ''"
        )->fetchAll(PDO::FETCH_ASSOC);

        $zugeordnet = 0;
        $offen = 0;

        $db->beginTransaction();
        try {
            // `oeffentlich` steht in der Spaltenliste NICHT im UPDATE-Zweig:
            // Sollte hier je ein zweites Mal geschrieben werden, darf die
            // Übernahme eine von Hand zurückgenommene Freigabe nicht wieder
            // setzen. Und im INSERT-Zweig ist der Wert 0 - der heutige Zustand
            // "bedingungslos öffentlich" wird bei der Übernahme bewusst NICHT
            // fortgeschrieben (Addons#132: Vorgabe ist "nicht öffentlich").
            $schreiben = $db->prepare(
                'INSERT INTO `' . Status::TABELLE . '` (contact_id, status, oeffentlich, altwert, offen, geaendert_von)
                 VALUES (:id, :status, 0, :altwert, :offen, :von)
                 ON DUPLICATE KEY UPDATE
                    status = VALUES(status),
                    altwert = VALUES(altwert),
                    offen = VALUES(offen),
                    geaendert_von = VALUES(geaendert_von)'
            );

            foreach ($zeilen as $zeile) {
                $kontaktId = (int) $zeile['id'];
                $wortlaut = (string) $zeile['membership_status'];
                $abgebildet = Werte::ausFreitext($wortlaut);

                if ($abgebildet === null) {
                    $offen++;
                } else {
                    $zugeordnet++;
                }

                $schreiben->execute([
                    'id' => $kontaktId,
                    'status' => $abgebildet ?? Werte::KEINE_ANGABE,
                    // Der Wortlaut bleibt IMMER erhalten, auch beim
                    // abgebildeten Fall: Er ist der Herkunftsnachweis und der
                    // Rückweg. Ohne ihn liesse sich die Freitextspalte des
                    // Kerns nicht mehr Zeichen für Zeichen wiederherstellen,
                    // und die Übernahme wäre eine Einbahnstrasse.
                    'altwert' => $wortlaut,
                    'offen' => $abgebildet === null ? 1 : 0,
                    'von' => 'Übernahme',
                ]);
            }

            self::markerSchreiben($db, [
                'zeitpunkt' => date('c'),
                'gesamt' => count($zeilen),
                'zugeordnet' => $zugeordnet,
                'offen' => $offen,
            ]);

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            // Weiterreichen: PluginManager fängt Fehler aus install() ab und
            // protokolliert sie (Kategorie `plugin`). Sie hier zu schlucken
            // hiesse, eine nicht gelaufene Übernahme wie eine gelaufene
            // aussehen zu lassen.
            throw $e;
        }

        Konfiguration::cacheVerwerfen();
        PluginAudit::log(
            Plugin::SLUG,
            'Bestandswerte übernommen',
            'Mitgliedsstatus',
            sprintf(
                '%d Kontakte gelesen, %d zugeordnet, %d Wortlaute zur Nachbearbeitung offen. '
                . 'Alle übernommenen Angaben sind zunächst NICHT öffentlich.',
                count($zeilen),
                $zugeordnet,
                $offen
            )
        );
    }

    /** Marker ohne Datenarbeit - für den Fall "es gibt dauerhaft nichts zu übernehmen". */
    private static function abschliessen(PDO $db, array $bericht): void {
        self::markerSchreiben($db, array_merge(['zeitpunkt' => date('c')], $bericht));
        Konfiguration::cacheVerwerfen();
    }

    /**
     * `VALUES(setting_value)` statt `setting_value = setting_value`: Hierher
     * kommt nur, wer die Prüfung am Anfang von einmalig() passiert hat, wer
     * also KEINEN gültigen Marker vorgefunden hat. Ein no-op-UPDATE liesse
     * einen leeren Altwert (etwa von Hand geleert) stehen - und damit liefe
     * die Übernahme bei jeder Aktivierung erneut, weil der Marker nie
     * zustandekäme.
     */
    private static function markerSchreiben(PDO $db, array $bericht): void {
        $db->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        )->execute([
            'k' => Konfiguration::SCHLUESSEL_UEBERNAHME,
            'v' => (string) json_encode($bericht, JSON_UNESCAPED_UNICODE),
        ]);
    }

    private static function tabelleExistiert(PDO $db, string $name): bool {
        try {
            $stmt = $db->query('SHOW TABLES LIKE ' . $db->quote($name));
            return $stmt !== false && $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function spalteExistiert(PDO $db, string $tabelle, string $spalte): bool {
        try {
            $stmt = $db->query(
                'SHOW COLUMNS FROM `' . str_replace('`', '``', $tabelle) . '` LIKE ' . $db->quote($spalte)
            );
            return $stmt !== false && $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

/**
 * Die drei Abschnitte, die dieses Addon in fremde Seiten hängt. Getrennt vom
 * Rest, damit der Text an einer Stelle steht und Anzeige und Verarbeitung
 * nicht auseinanderdriften.
 *
 * Alles, was aus der Datenbank kommt, wird hier mit htmlspecialchars()
 * escaped: Die Rückgabe der *_sections-Filter wird vom Kern UNESCAPED
 * ausgegeben, das Escaping ist Sache des Addons.
 */
final class Abschnitte {

    private function __construct() {}

    private static function e(string $roh): string {
        return htmlspecialchars($roh, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Öffentliche Kontaktseite. Erscheint nur, wenn BEIDES zutrifft: Die
     * Gast-Gruppe hat `mitgliedsstatus.view`, UND dieser Kontakt ist
     * ausdrücklich freigegeben. Fail-closed in beide Richtungen.
     *
     * Ausgegeben wird ausschliesslich der Wert aus der festen Liste. Der
     * gesicherte Bestandswortlaut (`altwert`) geht NIE nach draussen - er ist
     * ungeprüfter Altbestand, und genau seine Uneinheitlichkeit ist der Grund,
     * warum es dieses Addon gibt.
     *
     * @param array<string, mixed> $kontakt
     */
    public static function oeffentlich(array $kontakt): string {
        $id = isset($kontakt['id']) ? (int) $kontakt['id'] : 0;
        if ($id < 1) {
            return '';
        }

        if (!GroupMembership::hasPermission($_SESSION['user_id'] ?? null, Plugin::MODUL, 'view')) {
            return '';
        }

        $eintrag = Status::fuerKontakt($id);
        if (!$eintrag['oeffentlich'] || $eintrag['status'] === Werte::KEINE_ANGABE) {
            return '';
        }

        return '<h2 style="margin-top:0;font-size:1.1rem;">🎗 Mitgliedschaft</h2>'
            . '<p style="margin:0;font-weight:500;">' . self::e(Werte::label($eintrag['status'])) . '</p>';
    }

    /** Admin-Formular: Mitgliedsstatus und öffentliche Sichtbarkeit. */
    public static function statusPflege(int $kontaktId): string {
        $eintrag = Status::fuerKontakt($kontaktId);
        $csrf = self::e(Router::generateCsrfToken());

        $html = '<h2 style="margin-top:0;font-size:1.1rem;">🎗 Mitgliedsstatus</h2>';

        if ($eintrag['offen'] && $eintrag['altwert'] !== '') {
            $html .= '<p style="color:var(--danger-fg);background:var(--danger-soft-bg);padding:0.6rem;'
                . 'border-radius:var(--border-radius, 4px);">Bei der Übernahme liess sich der bisherige Wortlaut '
                . '<strong>' . self::e($eintrag['altwert']) . '</strong> nicht eindeutig zuordnen. '
                . 'Bitte hier entscheiden - der Wortlaut bleibt als Herkunftsnachweis erhalten.</p>';
        } elseif ($eintrag['altwert'] !== '') {
            $html .= '<p style="color:var(--text-muted);font-size:0.85em;">Übernommen aus dem Freitextfeld des Kerns: '
                . '<strong>' . self::e($eintrag['altwert']) . '</strong></p>';
        }

        $html .= '<form method="POST" action="/plugin/mitgliedsstatus/kontakt/status">';
        $html .= '<input type="hidden" name="csrf_token" value="' . $csrf . '">';
        $html .= '<input type="hidden" name="kontakt_id" value="' . $kontaktId . '">';

        foreach (Werte::alle() as $wert => $label) {
            $html .= '<label style="display:flex;gap:0.5rem;align-items:center;margin-bottom:0.25rem;">'
                . '<input type="radio" name="status" value="' . self::e($wert) . '"'
                . ($eintrag['status'] === $wert ? ' checked' : '') . '>'
                . '<span>' . self::e($label) . '</span></label>';
        }

        // Verstecktes Feld vor der Checkbox: Eine nicht angehakte Checkbox wird
        // gar nicht übertragen - ohne den Vorgabewert wäre "nicht öffentlich"
        // nicht von "Formular ohne dieses Feld" zu unterscheiden.
        $html .= '<hr style="border:none;border-top:1px solid var(--border-color);margin:0.75rem 0;">';
        $html .= '<input type="hidden" name="oeffentlich" value="0">';
        $html .= '<label style="display:flex;gap:0.5rem;align-items:center;">'
            . '<input type="checkbox" name="oeffentlich" value="1"' . ($eintrag['oeffentlich'] ? ' checked' : '') . '>'
            . '<span>Auf der öffentlichen Kontaktseite zeigen</span></label>';
        $html .= '<p style="color:var(--text-muted);font-size:0.85em;margin-top:0.35rem;">'
            . 'Vorgabe ist "nicht zeigen". Zusätzlich muss die Gast-Gruppe unter Gruppen das Recht '
            . '<em>Mitgliedsstatus → Freigegebene Angabe sehen</em> besitzen; ohne dieses Recht bleibt die Angabe '
            . 'auch bei gesetztem Häkchen unsichtbar.</p>';

        $html .= '<p style="color:var(--text-muted);font-size:0.85em;">Änderungen an den Stammdaten oben bitte zuerst '
            . 'speichern - dieser Knopf speichert ausschliesslich den Mitgliedsstatus.</p>';
        $html .= '<button type="submit" class="btn">Mitgliedsstatus übernehmen</button>';
        $html .= '</form>';

        return $html;
    }

    /** Admin-Formular: CiviCRM-Kennung und Link (Zuschnitt A aus Addons#130). */
    public static function civicrmPflege(int $kontaktId): string {
        $kennung = Verknuepfung::fuerKontakt($kontaktId);
        $basis = Konfiguration::civicrmBasis();
        $csrf = self::e(Router::generateCsrfToken());

        $html = '<h2 style="margin-top:0;font-size:1.1rem;">🔗 CiviCRM</h2>';
        $html .= '<p style="color:var(--text-muted);font-size:0.9em;">Die Kennung dieses Kontakts in der '
            . 'CiviCRM-Instanz des Verbands. Sie wird von Hand gesetzt oder importiert - es wird nichts '
            . 'abgeglichen, nichts übernommen und nie über Namensähnlichkeit geraten, wer zu wem gehört.</p>';

        if ($kennung !== null) {
            $link = Verknuepfung::link($kennung);
            if ($link !== null) {
                $html .= '<p><a href="' . self::e($link) . '" target="_blank" rel="noopener noreferrer" '
                    . 'class="btn btn-secondary">In CiviCRM öffnen (Kontakt ' . $kennung . ')</a></p>';
            } else {
                $html .= '<p style="color:var(--text-muted);font-size:0.85em;">Für einen Link fehlt die Basis-URL der '
                    . 'CiviCRM-Instanz. Sie steht unter <a href="' . self::e(Plugin::VERWALTUNG) . '">Dashboard → '
                    . 'Mitgliedsstatus</a>.</p>';
            }
        }

        $html .= '<form method="POST" action="/plugin/mitgliedsstatus/kontakt/civicrm">';
        $html .= '<input type="hidden" name="csrf_token" value="' . $csrf . '">';
        $html .= '<input type="hidden" name="kontakt_id" value="' . $kontaktId . '">';
        $html .= '<div class="form-group"><label for="ms_civicrm_id">CiviCRM-Kontakt-ID</label>'
            . '<input type="text" inputmode="numeric" id="ms_civicrm_id" name="civicrm_contact_id" class="form-control" '
            . 'value="' . ($kennung !== null ? (string) $kennung : '') . '" placeholder="z. B. 4711"></div>';
        $html .= '<p style="color:var(--text-muted);font-size:0.85em;">Leeres Feld entfernt die Zuordnung. '
            . ($basis === '' ? 'Ohne hinterlegte Basis-URL wird die Kennung gespeichert, aber nicht verlinkt.' : '')
            . '</p>';
        $html .= '<button type="submit" class="btn">CiviCRM-Zuordnung übernehmen</button>';
        $html .= '</form>';

        return $html;
    }
}

/**
 * Schreibt die beiden Angaben eines EINZELNEN Kontakts.
 *
 * Eigener Controller mit eigener Berechtigung: Geprüft wird `contacts.edit` -
 * wer einen Kontakt bearbeiten darf, darf auch sagen, ob er Mitglied ist. Das
 * Recht `mitgliedsstatus.manage` gilt für die Verwaltungsseite (Nacharbeit der
 * Übernahme, Einstellungen) und wird hier ausdrücklich NICHT verlangt - sonst
 * bräuchte jede Redaktionskraft ein Verwaltungsrecht, um ein Häkchen zu
 * setzen.
 */
class KontaktController extends BaseController {

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
    }

    public function status(): void {
        $kontaktId = $this->geprueftesZiel();

        $status = Werte::ausEingabe($_POST['status'] ?? null);
        $oeffentlich = ($_POST['oeffentlich'] ?? '0') === '1';

        Status::setzen($kontaktId, $status, $oeffentlich, $this->benutzername());

        // Der Statuswert steht mit im Protokoll, die Kennung des Kontakts nur
        // als Nummer. Das ist die Grenze aus docs/plugin-development.md
        // ("Der Name eines Datensatzes ist in Ordnung, der Inhalt eines
        // Kontaktfelds nicht"), hier bewusst so gezogen: Ein Protokolleintrag
        // "Kontakt #42 auf Nichtmitglied gesetzt, öffentlich" ist genau der
        // Nachweis, für den es dieses Protokoll gibt - ohne den Wert stünde
        // dort "etwas wurde geändert". Ein zustellbares Datum (Name, Adresse,
        // E-Mail) geht hier nie hinein.
        PluginAudit::log(
            Plugin::SLUG,
            'Mitgliedsstatus gesetzt',
            'Kontakt #' . $kontaktId,
            Werte::label($status) . ', ' . ($oeffentlich ? 'öffentlich sichtbar' : 'nicht öffentlich')
        );

        $this->zurueck($kontaktId, 'status');
    }

    public function civicrm(): void {
        $kontaktId = $this->geprueftesZiel();

        [$gueltig, $kennung] = Verknuepfung::kennungAusEingabe($_POST['civicrm_contact_id'] ?? null);
        if (!$gueltig) {
            $this->zurueck($kontaktId, 'civicrm-ungueltig');
        }

        Verknuepfung::setzen($kontaktId, $kennung, $this->benutzername());

        // Die CiviCRM-Kennung selbst geht NICHT ins Protokoll: Sie ist die
        // Kennung eines Menschen in einem fremden System, und `audit_logs`
        // wird von keiner Löschfrist erfasst - sie überlebte damit jede
        // DSGVO-Löschung des Kontakts.
        PluginAudit::log(
            Plugin::SLUG,
            $kennung === null ? 'CiviCRM-Zuordnung entfernt' : 'CiviCRM-Zuordnung gesetzt',
            'Kontakt #' . $kontaktId
        );

        $this->zurueck($kontaktId, 'civicrm');
    }

    /**
     * Kontakt-Kennung aus dem Formular, danach die Rechteprüfung. Beides
     * gehört zusammen und steht deshalb an einer Stelle.
     */
    private function geprueftesZiel(): int {
        if (!Router::verifyCsrfToken(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }

        $id = filter_var($_POST['kontakt_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($id)) {
            header('Location: /admin/contacts');
            exit;
        }

        $this->requirePermission('contacts', 'edit');

        // Der Fremdschlüssel auf `contacts` würde eine erfundene Kennung ohnehin
        // abweisen - aber mit einer Datenbank-Ausnahme statt einer Antwort.
        $stmt = Database::getInstance()->prepare('SELECT 1 FROM contacts WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() === false) {
            header('Location: /admin/contacts');
            exit;
        }

        return $id;
    }

    private function benutzername(): string {
        $name = trim((string) ($_SESSION['username'] ?? 'unbekannt'));
        return mb_substr($name === '' ? 'unbekannt' : $name, 0, 100);
    }

    private function zurueck(int $kontaktId, string $status): never {
        header('Location: /admin/contacts/edit?id=' . $kontaktId . '&ms=' . $status);
        exit;
    }
}

/**
 * Verwaltungsseite: Bericht der Übernahme, Nacharbeit der Wortlaute, die sich
 * nicht abbilden liessen, CiviCRM-Basis-URL und der Umgang mit der
 * Freitextspalte des Kerns.
 */
class VerwaltungController extends BaseController {

    /** Mehr Wortlaute als das hat kein realer Bestand - der Deckel schützt die Seite, nicht die Daten. */
    private const MAX_WORTLAUTE = 200;

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
        $this->requirePermission(Plugin::MODUL, 'manage');
    }

    public function index(): void {
        $inhalt = self::meldung();
        $inhalt .= $this->uebernahmeKarte();
        $inhalt .= $this->offeneKarte();
        $inhalt .= $this->civicrmKarte();
        $inhalt .= $this->kernFreitextKarte();

        PluginPage::render('Mitgliedsstatus', $inhalt);
    }

    /**
     * Weist allen Kontakten mit EXAKT diesem Bestandswortlaut denselben Status
     * zu. Das ist die Stelle, an der ein Mensch entscheidet, was die Übernahme
     * bewusst nicht geraten hat.
     */
    public function zuordnen(): void {
        $this->pruefeCsrf();

        $wortlaut = trim(is_string($_POST['wortlaut'] ?? null) ? $_POST['wortlaut'] : '');
        $status = Werte::ausEingabe($_POST['status'] ?? null);
        if ($wortlaut === '') {
            $this->zurueck('fehler');
        }

        // Der Vergleich läuft in der Kollation der Spalte (utf8mb4_unicode_ci)
        // und fasst damit reine Schreibweisen-Varianten zusammen - genau so,
        // wie die Liste sie oben schon per GROUP BY zu EINER Zeile
        // zusammengefasst hat. Anzeige und Verarbeitung müssen dieselbe Regel
        // anwenden, sonst bliebe nach dem Zuordnen eine Variante übrig, die in
        // der Liste nie zu sehen war.
        $stmt = Database::getInstance()->prepare(
            'UPDATE `' . Status::TABELLE . '`
             SET status = :status, offen = 0, geaendert_von = :von
             WHERE offen = 1 AND altwert = :wortlaut'
        );
        $stmt->execute(['status' => $status, 'von' => $this->benutzername(), 'wortlaut' => $wortlaut]);
        $betroffen = $stmt->rowCount();

        // Der Wortlaut selbst steht NICHT im Protokoll: Trägt ihn nur ein
        // einziger Kontakt, wäre er der Inhalt eines Kontaktfelds - und
        // `audit_logs` kennt keine Löschfrist. Die Zahl und der Zielwert sagen,
        // was geschehen ist.
        PluginAudit::log(
            Plugin::SLUG,
            'Bestandswortlaut zugeordnet',
            'Mitgliedsstatus',
            sprintf('%d Kontakte auf "%s" gesetzt', $betroffen, Werte::label($status))
        );

        $this->zurueck($betroffen > 0 ? 'zugeordnet' : 'nichts-zugeordnet');
    }

    public function civicrmUrl(): void {
        $this->pruefeCsrf();

        $roh = is_string($_POST['basis_url'] ?? null) ? $_POST['basis_url'] : '';
        [$gueltig, $basis] = Konfiguration::basisPruefen($roh);
        if (!$gueltig) {
            $this->zurueck('url-ungueltig');
        }

        Konfiguration::setzen(Konfiguration::SCHLUESSEL_URL, $basis);
        PluginAudit::log(
            Plugin::SLUG,
            $basis === '' ? 'CiviCRM-Basis-URL entfernt' : 'CiviCRM-Basis-URL gesetzt',
            'Mitgliedsstatus',
            $basis === '' ? null : $basis
        );

        $this->zurueck('gespeichert');
    }

    /**
     * Der Umgang mit der Freitextspalte des Kerns, nachdem die Übernahme
     * gelaufen ist - beide Richtungen, beide ausdrücklich vom Betreiber
     * ausgelöst.
     *
     * WARUM DAS ÜBERHAUPT HIER STEHT: Bis v0.9.0 gibt es die Spalte noch, und
     * der Kern zeigt sie weiter auf der öffentlichen Kontaktseite an - und
     * zwar bedingungslos. Solange sie befüllt ist, steht die alte, ungeprüfte
     * Angabe also neben der neuen, gepflegten, und die Freigabe je Kontakt aus
     * diesem Addon läuft ins Leere. Das Leeren ist der Weg, das aufzulösen.
     *
     * WARUM ES NICHT AUTOMATISCH GESCHIEHT: Es ist eine Änderung an einer
     * KERN-Tabelle. Sie nimmt ausserdem der Züchtersuche ihren Filter
     * "Mitgliedsstatus", solange dieser noch auf der Kern-Spalte sitzt. Das
     * entscheidet der Betreiber, nicht die Aktivierung eines Addons.
     *
     * WARUM ES SICHER IST: Geleert wird ausschliesslich, wo der aktuelle
     * Spaltenwert Zeichen für Zeichen der gesicherten Fassung entspricht.
     * Wurde der Wert nach der Übernahme von Hand geändert, bleibt er stehen -
     * eine Änderung, die niemand gesichert hat, darf keine Automatik
     * wegräumen. Und der Rückweg stellt aus derselben Sicherung wieder her.
     */
    public function kernFreitext(): void {
        $this->pruefeCsrf();

        // Zusätzlich zu `mitgliedsstatus.manage`: Hier wird in `contacts`
        // geschrieben, und dafür gilt das Recht des Kerns.
        $this->requirePermission('contacts', 'edit');

        if (Konfiguration::uebernahmeBericht() === null) {
            $this->zurueck('keine-uebernahme');
        }
        if (!self::kernSpalteVorhanden()) {
            $this->zurueck('keine-spalte');
        }

        $aktion = is_string($_POST['aktion'] ?? null) ? $_POST['aktion'] : '';
        $db = Database::getInstance();

        if ($aktion === 'leeren') {
            // CAST(... AS BINARY): Der Vergleich muss hier Zeichen für Zeichen
            // gelten, nicht in der Kollation der Spalte. utf8mb4_unicode_ci
            // hielte 'mitglied' und 'Mitglied' für denselben Wert - dann
            // löschte dieser Knopf eine von Hand geänderte Schreibweise, und
            // der Rückweg schriebe die andere zurück. "Byte-identisch" heisst
            // byte-identisch.
            $stmt = $db->prepare(
                'UPDATE contacts c
                 JOIN `' . Status::TABELLE . '` k ON k.contact_id = c.id
                 SET c.membership_status = NULL
                 WHERE k.altwert IS NOT NULL AND c.membership_status = CAST(k.altwert AS BINARY)'
            );
            $stmt->execute();
            $betroffen = $stmt->rowCount();

            PluginAudit::log(
                Plugin::SLUG,
                'Freitextspalte des Kerns geleert',
                'Mitgliedsstatus',
                sprintf('%d Kontakte; die Wortlaute bleiben als Sicherung im Addon erhalten', $betroffen)
            );
            $this->zurueck($betroffen > 0 ? 'geleert' : 'nichts-geleert');
        }

        if ($aktion === 'wiederherstellen') {
            $stmt = $db->prepare(
                "UPDATE contacts c
                 JOIN `" . Status::TABELLE . "` k ON k.contact_id = c.id
                 SET c.membership_status = k.altwert
                 WHERE k.altwert IS NOT NULL AND k.altwert <> ''
                   AND (c.membership_status IS NULL OR c.membership_status = '')"
            );
            $stmt->execute();
            $betroffen = $stmt->rowCount();

            PluginAudit::log(
                Plugin::SLUG,
                'Freitextspalte des Kerns wiederhergestellt',
                'Mitgliedsstatus',
                sprintf('%d Kontakte aus der Sicherung zurückgeschrieben', $betroffen)
            );
            $this->zurueck($betroffen > 0 ? 'wiederhergestellt' : 'nichts-wiederhergestellt');
        }

        $this->zurueck('fehler');
    }

    private function uebernahmeKarte(): string {
        $bericht = Konfiguration::uebernahmeBericht();

        $html = '<div class="card"><h2 style="margin-top:0;">Übernahme der Bestandswerte</h2>';

        if ($bericht === null) {
            $html .= '<p style="color:var(--danger-fg);background:var(--danger-soft-bg);padding:0.6rem;'
                . 'border-radius:var(--border-radius, 4px);">Die Übernahme ist noch nicht gelaufen. Sie läuft bei der '
                . 'Aktivierung des Addons - erscheint diese Meldung dauerhaft, fehlt dem Kern die Tabelle '
                . '<code>contacts</code> (Kern-Version prüfen).</p></div>';
            return $html;
        }

        if (($bericht['grund'] ?? '') === 'keine-spalte') {
            $html .= '<p>Der Kern führt kein Freitextfeld <code>membership_status</code> mehr - es gab nichts zu '
                . 'übernehmen. Der Mitgliedsstatus wird ab hier ausschliesslich in diesem Addon gepflegt.</p></div>';
            return $html;
        }

        $html .= '<p>Gelesen: <strong>' . (int) ($bericht['gesamt'] ?? 0) . '</strong> Kontakte mit einem Eintrag. '
            . 'Zugeordnet: <strong>' . (int) ($bericht['zugeordnet'] ?? 0) . '</strong>. '
            . 'Zur Nachbearbeitung offen: <strong>' . (int) ($bericht['offen'] ?? 0) . '</strong>.</p>';
        $html .= '<p style="color:var(--text-muted);font-size:0.9em;">Alle übernommenen Angaben sind zunächst '
            . '<strong>nicht öffentlich</strong> - anders als im Kern, wo sie es bedingungslos waren. Die Freigabe '
            . 'geschieht je Kontakt im Bearbeitungsformular. Nicht abbildbare Wortlaute wurden nicht verworfen, '
            . 'sondern unverändert festgehalten.</p>';
        $html .= '</div>';

        return $html;
    }

    private function offeneKarte(): string {
        $csrf = htmlspecialchars(Router::generateCsrfToken(), ENT_QUOTES, 'UTF-8');

        try {
            $stmt = Database::getInstance()->prepare(
                'SELECT altwert, COUNT(*) AS anzahl
                 FROM `' . Status::TABELLE . '`
                 WHERE offen = 1 AND altwert IS NOT NULL AND altwert <> \'\'
                 GROUP BY altwert
                 ORDER BY anzahl DESC, altwert ASC
                 LIMIT ' . self::MAX_WORTLAUTE
            );
            $stmt->execute();
            $gruppen = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $gruppen = [];
        }

        $html = '<div class="card"><h2 style="margin-top:0;">Offene Wortlaute</h2>';

        if ($gruppen === []) {
            $html .= '<p style="color:var(--text-muted);">Nichts offen - jeder übernommene Wortlaut liess sich '
                . 'zuordnen oder wurde bereits entschieden.</p></div>';
            return $html;
        }

        $html .= '<p style="color:var(--text-muted);font-size:0.9em;">Diese Wortlaute standen im Freitextfeld des '
            . 'Kerns und liessen sich nicht ohne Raten auf die Werteliste abbilden. Die Zuordnung gilt jeweils für '
            . 'alle Kontakte mit exakt diesem Wortlaut; der Wortlaut selbst bleibt als Herkunftsnachweis erhalten.</p>';
        $html .= '<table style="width:100%;border-collapse:collapse;">';

        foreach ($gruppen as $gruppe) {
            $wortlaut = (string) $gruppe['altwert'];
            $sicher = htmlspecialchars($wortlaut, ENT_QUOTES, 'UTF-8');

            $html .= '<tr><td style="padding:0.5rem 0;border-bottom:1px solid var(--border-color);">'
                . '<strong>' . $sicher . '</strong> '
                . '<span style="color:var(--text-muted);">(' . (int) $gruppe['anzahl'] . ' Kontakte)</span></td>'
                . '<td style="padding:0.5rem 0;border-bottom:1px solid var(--border-color);text-align:right;">'
                . '<form method="POST" action="/plugin/mitgliedsstatus/verwaltung/zuordnen" '
                . 'style="display:flex;gap:0.5rem;justify-content:flex-end;">'
                . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
                . '<input type="hidden" name="wortlaut" value="' . $sicher . '">'
                . '<select name="status" class="form-control" style="width:auto;">';

            foreach (Werte::alle() as $wert => $label) {
                $html .= '<option value="' . htmlspecialchars($wert, ENT_QUOTES, 'UTF-8') . '">'
                    . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
            }

            $html .= '</select><button type="submit" class="btn">Zuordnen</button></form></td></tr>';
        }

        $html .= '</table></div>';

        return $html;
    }

    private function civicrmKarte(): string {
        $csrf = htmlspecialchars(Router::generateCsrfToken(), ENT_QUOTES, 'UTF-8');
        $basis = htmlspecialchars(Konfiguration::civicrmBasis(), ENT_QUOTES, 'UTF-8');

        $html = '<div class="card"><h2 style="margin-top:0;">CiviCRM-Verlinkung</h2>';
        $html .= '<p style="color:var(--text-muted);font-size:0.9em;">Basis-URL der CiviCRM-Instanz. Damit entsteht '
            . 'im Bearbeitungsformular eines Kontakts ein Link auf <code>/civicrm/contact/view?reset=1&amp;cid=…</code>. '
            . 'Es werden <strong>keine</strong> Daten abgefragt, übernommen oder zurückgeschrieben - dieses Addon '
            . 'speichert eine Kennung und baut einen Link, mehr nicht.</p>';
        $html .= '<form method="POST" action="/plugin/mitgliedsstatus/verwaltung/civicrm-url">';
        $html .= '<input type="hidden" name="csrf_token" value="' . $csrf . '">';
        $html .= '<div class="form-group"><label for="ms_basis_url">Basis-URL</label>'
            . '<input type="text" id="ms_basis_url" name="basis_url" class="form-control" value="' . $basis . '" '
            . 'placeholder="https://crm.beispiel-verband.de"></div>';
        $html .= '<p style="color:var(--text-muted);font-size:0.85em;">Leeres Feld entfernt die Basis-URL; '
            . 'gespeicherte Kennungen bleiben erhalten, nur der Link entfällt.</p>';
        $html .= '<button type="submit" class="btn">Basis-URL speichern</button></form></div>';

        return $html;
    }

    private function kernFreitextKarte(): string {
        $html = '<div class="card"><h2 style="margin-top:0;">Freitextfeld des Kerns</h2>';

        if (!self::kernSpalteVorhanden()) {
            $html .= '<p style="color:var(--text-muted);">Der Kern führt die Spalte '
                . '<code>contacts.membership_status</code> nicht mehr. Hier ist nichts mehr zu tun.</p></div>';
            return $html;
        }

        if (Konfiguration::uebernahmeBericht() === null) {
            $html .= '<p style="color:var(--text-muted);">Erst nach der Übernahme.</p></div>';
            return $html;
        }

        [$befuellt, $gesichert, $rueckholbar] = self::kernFreitextZahlen();
        $csrf = htmlspecialchars(Router::generateCsrfToken(), ENT_QUOTES, 'UTF-8');

        $html .= '<p style="color:var(--text-muted);font-size:0.9em;">Bis Kern-v0.9.0 gibt es das Freitextfeld noch, '
            . 'und der Kern zeigt es auf der öffentlichen Kontaktseite <strong>bedingungslos</strong> an. Solange es '
            . 'befüllt ist, steht die alte Angabe neben der hier gepflegten, und die Freigabe je Kontakt aus diesem '
            . 'Addon läuft ins Leere.</p>';
        $html .= '<p>Noch befüllt: <strong>' . $befuellt . '</strong>. Davon Zeichen für Zeichen wie gesichert: '
            . '<strong>' . $gesichert . '</strong>. Aus der Sicherung rückholbar: <strong>' . $rueckholbar
            . '</strong>.</p>';
        $html .= '<p style="color:var(--text-muted);font-size:0.85em;">Geleert wird ausschliesslich, wo der Wert der '
            . 'Sicherung entspricht - nach der Übernahme von Hand geänderte Werte bleiben stehen. Beachten: Der Filter '
            . '„Mitgliedsstatus" der Züchtersuche liest bis auf Weiteres die Kern-Spalte und findet nach dem Leeren '
            . 'nichts mehr.</p>';

        $html .= '<form method="POST" action="/plugin/mitgliedsstatus/verwaltung/kern-freitext" '
            . 'style="display:flex;gap:0.5rem;flex-wrap:wrap;">'
            . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
            . '<button type="submit" name="aktion" value="leeren" class="btn">Gesicherte Werte im Kern leeren</button>'
            . '<button type="submit" name="aktion" value="wiederherstellen" class="btn btn-secondary">'
            . 'Aus der Sicherung wiederherstellen</button></form>';

        return $html . '</div>';
    }

    /** @return array{0:int,1:int,2:int} [befüllt, deckungsgleich gesichert, rückholbar] */
    private static function kernFreitextZahlen(): array {
        $db = Database::getInstance();

        try {
            $befuellt = (int) $db->query(
                "SELECT COUNT(*) FROM contacts WHERE membership_status IS NOT NULL AND TRIM(membership_status) <> ''"
            )->fetchColumn();

            // Dieselbe byte-genaue Bedingung wie im Leeren-Pfad - die
            // angezeigte Zahl muss die sein, die der Knopf danach trifft.
            $gesichert = (int) $db->query(
                'SELECT COUNT(*) FROM contacts c JOIN `' . Status::TABELLE . '` k ON k.contact_id = c.id
                 WHERE k.altwert IS NOT NULL AND c.membership_status = CAST(k.altwert AS BINARY)'
            )->fetchColumn();

            $rueckholbar = (int) $db->query(
                "SELECT COUNT(*) FROM contacts c JOIN `" . Status::TABELLE . "` k ON k.contact_id = c.id
                 WHERE k.altwert IS NOT NULL AND k.altwert <> ''
                   AND (c.membership_status IS NULL OR c.membership_status = '')"
            )->fetchColumn();
        } catch (\Throwable $e) {
            return [0, 0, 0];
        }

        return [$befuellt, $gesichert, $rueckholbar];
    }

    private static function kernSpalteVorhanden(): bool {
        try {
            $db = Database::getInstance();
            $stmt = $db->query('SHOW COLUMNS FROM `contacts` LIKE ' . $db->quote('membership_status'));
            return $stmt !== false && $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function meldung(): string {
        $status = is_string($_GET['ms'] ?? null) ? $_GET['ms'] : '';

        $texte = [
            'gespeichert' => ['Einstellungen gespeichert.', 'success'],
            'zugeordnet' => ['Wortlaut zugeordnet.', 'success'],
            'nichts-zugeordnet' => ['Zu diesem Wortlaut war nichts mehr offen.', 'danger'],
            'geleert' => ['Die gesicherten Werte wurden im Kern geleert.', 'success'],
            'nichts-geleert' => ['Es gab keinen Wert, der Zeichen für Zeichen der Sicherung entspricht.', 'danger'],
            'wiederhergestellt' => ['Die Werte wurden aus der Sicherung zurückgeschrieben.', 'success'],
            'nichts-wiederhergestellt' => ['Es gab nichts zurückzuschreiben.', 'danger'],
            'url-ungueltig' => ['Die Basis-URL muss mit http:// oder https:// beginnen und ohne Query enden.', 'danger'],
            'keine-uebernahme' => ['Die Übernahme ist noch nicht gelaufen - ohne Sicherung wird hier nichts geändert.', 'danger'],
            'keine-spalte' => ['Der Kern führt die Spalte membership_status nicht mehr.', 'danger'],
            'fehler' => ['Die Aktion konnte nicht ausgeführt werden.', 'danger'],
        ];

        if (!isset($texte[$status])) {
            return '';
        }

        [$text, $art] = $texte[$status];
        return '<div class="card" style="color:var(--' . $art . '-fg);background:var(--' . $art . '-soft-bg);">'
            . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</div>';
    }

    private function pruefeCsrf(): void {
        if (!Router::verifyCsrfToken(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }
    }

    private function benutzername(): string {
        $name = trim((string) ($_SESSION['username'] ?? 'unbekannt'));
        return mb_substr($name === '' ? 'unbekannt' : $name, 0, 100);
    }

    private function zurueck(string $status): never {
        header('Location: ' . Plugin::VERWALTUNG . '?ms=' . $status);
        exit;
    }
}
